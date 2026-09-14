<?php

namespace DE\RUB\SEG\LegacyURLFixerExternalModule;

use ExternalModules\ExternalModules;

/**
 * Finds and repairs stored REDCap image/file URLs that use the pre-September
 * 2026 document hash algorithm.
 *
 * The scanner deliberately covers authored project content only. It does not
 * inspect record data or historical delivery/audit tables.
 */
class LegacyURLFixerExternalModule extends \ExternalModules\AbstractExternalModule
{
    private const SCAN_CACHE_KEY = 'scan-cache';
    private const CONTROL_CENTER_SCAN_CACHE_PREFIX = 'control-center-scan-';
    private const MAX_SCAN_CACHE_BYTES = 14000000;
    private const DETAIL_PAGE_SIZE = 50;

    /**
     * Matches absolute image/file URLs, including survey passthru URLs.
     * Host and legacy-hash checks are made separately before a URL qualifies.
     */
    private const URL_PATTERN = '~(?:
        https?://[^\s<>"\']*?DataEntry/(?:image_view|file_download)\.php\?[^\s<>"\']*
        |
        https?://[^\s<>"\']*?Surveys/index\.php\?[^\s<>"\']*?__passthru=DataEntry(?:%2F|/)(?:image_view|file_download)\.php[^\s<>"\']*
    )~ix';

    /** @var array<string, array<string, string>|false> */
    private array $documentCache = [];

    /**
     * Processes authenticated, CSRF-protected requests made through the
     * External Module Framework JavaScript object.
     */
    public function redcap_module_ajax(
        $action,
        $payload,
        $project_id,
        $record,
        $instrument,
        $event_id,
        $repeat_instance,
        $survey_hash,
        $response_id,
        $survey_queue_hash,
        $page,
        $page_full,
        $user_id,
        $group_id
    ) {
        if (in_array($action, ['control-center-scan', 'control-center-status'], true)) {
            $this->requireControlCenterAccess();
            if ($action === 'control-center-status') {
                return $this->getControlCenterScanStatus();
            }
            $surfaceId = is_array($payload) ? ($payload['surface_id'] ?? null) : null;
            return $this->runControlCenterScan($surfaceId);
        }

        if (!is_numeric($project_id)) {
            throw new \Exception('A project context is required.');
        }

        $projectId = (int) $project_id;
        ExternalModules::requireDesignRights($projectId);

        $project = $this->getProject($projectId);
        if ($project === null) {
            throw new \Exception('The project could not be found.');
        }

        if ($action === 'scan') {
            return $this->runScan($project);
        }

        if ($action === 'status') {
            return $this->getCachedScanSummary($projectId);
        }

        if ($action === 'details') {
            $requestedScanId = is_array($payload) ? ($payload['scan_id'] ?? null) : null;
            $offset = is_array($payload) ? ($payload['offset'] ?? 0) : 0;
            return $this->getCachedScanDetails($project, $requestedScanId, $offset);
        }

        if ($action === 'apply') {
            $requestedScanId = is_array($payload) ? ($payload['scan_id'] ?? null) : null;
            return $this->applyCachedScan($project, $requestedScanId);
        }

        throw new \Exception('Unsupported Legacy URL Fixer action.');
    }

    private function requireControlCenterAccess(): void
    {
        if (!$this->isSuperUser()) {
            throw new \Exception('Only a REDCap super user may run the Control Center scan.');
        }
    }

    /**
     * Returns the safe, small portion of the cached scan suitable for the UI.
     */
    public function getCachedScanSummary($projectId): ?array
    {
        $scan = $this->getCachedScan($projectId);
        return $scan === null ? null : $this->scanSummary($scan);
    }

    /**
     * Scan all live, authored surfaces and cache locators plus old-value
     * fingerprints. Source values themselves are never cached.
     */
    private function runScan(array $project): array
    {
        $projectId = (int) $project['project_id'];
        $scan = [
            'id' => bin2hex(random_bytes(16)),
            'project_id' => $projectId,
            'created_at' => date('c'),
            'project_status' => (int) $project['status'],
            'draft_mode' => (int) $project['draft_mode'],
            'items' => [],
            // Like repair items, these are locators and fingerprints only.
            // The current text and URL previews are fetched on demand.
            'detail_items' => [],
            'stats' => [
                'changed_cells' => 0,
                'changed_urls' => 0,
                'current_urls' => 0,
                'issues' => 0,
                'issues_by_reason' => [],
                'by_hash_state' => [],
                'surfaces' => [],
                'skipped_surfaces' => [],
            ],
        ];

        foreach ($this->getScanSurfaces($project) as $surface) {
            $this->scanSurface($surface, $project, $scan);
        }

        $encodedScan = json_encode($scan);
        if ($encodedScan === false || strlen($encodedScan) > self::MAX_SCAN_CACHE_BYTES) {
            throw new \Exception('The scan result is too large to cache safely. Narrow the scope or contact a REDCap administrator.');
        }

        $this->setProjectSetting(self::SCAN_CACHE_KEY, $encodedScan, $projectId);
        return $this->scanSummary($scan);
    }

    /**
     * Scan one physical surface across non-deleted projects. This intentionally
     * records only project IDs: project-level scan and repair remain the place
     * to inspect configuration content.
     */
    private function runControlCenterScan($surfaceId): array
    {
        if (!is_string($surfaceId)) {
            throw new \Exception('A Control Center scan surface is required.');
        }
        $surfaces = [];
        foreach ($this->getControlCenterScanSurfaces() as $surface) {
            $surfaces[$surface['id']] = $surface;
        }
        $surface = $surfaces[$surfaceId] ?? null;
        if ($surface === null) {
            throw new \Exception('The requested Control Center scan surface is not available.');
        }

        $scan = [
            'surface_id' => $surface['id'],
            'created_at' => date('c'),
            'status' => 'complete',
            'message' => null,
            'project_ids' => [],
        ];
        $columns = $this->getColumns($surface['table']);
        if ($columns === []) {
            $scan['status'] = 'unavailable';
            $scan['message'] = 'The table is not available in this REDCap schema.';
            return $this->cacheControlCenterScan($scan, $surface);
        }

        $primaryKeys = $this->getPrimaryKeys($columns);
        $textColumns = $this->getTextColumns($columns, array_merge($primaryKeys, $surface['exclude'] ?? []));
        if ($textColumns === []) {
            $scan['status'] = 'unavailable';
            $scan['message'] = 'The table has no scanable text columns.';
            return $this->cacheControlCenterScan($scan, $surface);
        }

        $scope = $this->getControlCenterScopeClause($surface, $columns);
        if ($scope === null) {
            $scan['status'] = 'unavailable';
            $scan['message'] = 'The required project relationship is not available in this REDCap schema.';
            return $this->cacheControlCenterScan($scan, $surface);
        }

        $candidateWhere = $this->getCandidateWhereClause($textColumns, 't');
        $selectColumns = ['p.`project_id` AS `control_center_project_id`'];
        foreach ($textColumns as $column) {
            $selectColumns[] = 't.' . $this->identifier($column);
        }
        $sql = 'SELECT ' . implode(', ', $selectColumns)
            . ' FROM ' . $this->identifier($surface['table']) . ' t'
            . $scope['join']
            . ' WHERE (' . $scope['sql'] . ') AND (' . $candidateWhere['sql'] . ')';
        $result = $this->query($sql, $candidateWhere['params']);

        $this->documentCache = [];
        $affected = [];
        while ($row = $result->fetch_assoc()) {
            $projectId = $row['control_center_project_id'] ?? null;
            if (!$this->isInteger($projectId) || (int) $projectId < 1) {
                continue;
            }
            $projectId = (int) $projectId;
            foreach ($textColumns as $column) {
                $value = $row[$column] ?? '';
                if (!is_string($value) || !$this->containsPotentialUrl($value)) {
                    continue;
                }
                $upgraded = $this->upgradeText($value, ['project_id' => $projectId]);
                if ($upgraded['changed'] > 0 || $upgraded['issues'] > 0) {
                    $affected[$projectId] = true;
                    break;
                }
            }
        }

        $scan['project_ids'] = array_map('intval', array_keys($affected));
        sort($scan['project_ids'], SORT_NUMERIC);
        return $this->cacheControlCenterScan($scan, $surface);
    }

    /** @return array{surfaces: array<int, array<string, mixed>>} */
    private function getControlCenterScanStatus(): array
    {
        $summaries = [];
        foreach ($this->getControlCenterScanSurfaces() as $surface) {
            $cached = $this->getControlCenterCachedScan($surface['id']);
            $summaries[] = $cached === null
                ? [
                    'surface_id' => $surface['id'],
                    'label' => $surface['label'],
                    'created_at' => null,
                    'status' => 'not-scanned',
                    'message' => null,
                    'project_ids' => [],
                    'project_count' => 0,
                ]
                : $this->controlCenterScanSummary($cached, $surface);
        }
        return ['surfaces' => $summaries];
    }

    private function cacheControlCenterScan(array $scan, array $surface): array
    {
        $encoded = json_encode($scan);
        if ($encoded === false || strlen($encoded) > self::MAX_SCAN_CACHE_BYTES) {
            throw new \Exception('The Control Center scan result is too large to cache safely.');
        }
        $this->setSystemSetting(self::CONTROL_CENTER_SCAN_CACHE_PREFIX . $surface['id'], $encoded);
        return $this->controlCenterScanSummary($scan, $surface);
    }

    private function getControlCenterCachedScan(string $surfaceId): ?array
    {
        $value = $this->getSystemSetting(self::CONTROL_CENTER_SCAN_CACHE_PREFIX . $surfaceId);
        if (!is_string($value) || $value === '') {
            return null;
        }
        $scan = json_decode($value, true);
        if (!is_array($scan)
            || ($scan['surface_id'] ?? null) !== $surfaceId
            || !is_string($scan['created_at'] ?? null)
            || !is_string($scan['status'] ?? null)
            || !is_array($scan['project_ids'] ?? null)
        ) {
            return null;
        }
        $projectIds = [];
        foreach ($scan['project_ids'] as $projectId) {
            if ($this->isInteger($projectId) && (int) $projectId > 0) {
                $projectIds[(int) $projectId] = true;
            }
        }
        $scan['project_ids'] = array_map('intval', array_keys($projectIds));
        sort($scan['project_ids'], SORT_NUMERIC);
        $scan['message'] = is_string($scan['message'] ?? null) ? $scan['message'] : null;
        return $scan;
    }

    private function controlCenterScanSummary(array $scan, array $surface): array
    {
        $projectIds = $scan['project_ids'];
        return [
            'surface_id' => $surface['id'],
            'label' => $surface['label'],
            'created_at' => $scan['created_at'],
            'status' => $scan['status'],
            'message' => $scan['message'],
            'project_ids' => $projectIds,
            'project_count' => count($projectIds),
        ];
    }

    private function scanSurface(array $surface, array $project, array &$scan): void
    {
        $projectId = (int) $project['project_id'];
        $columns = $this->getColumns($surface['table']);
        if ($columns === []) {
            $scan['stats']['skipped_surfaces'][] = $surface['label'] . ' (table not available)';
            return;
        }

        $primaryKeys = $this->getPrimaryKeys($columns);
        $textColumns = $this->getTextColumns($columns, array_merge($primaryKeys, $surface['exclude'] ?? []));
        if ($primaryKeys === [] || $textColumns === []) {
            $scan['stats']['skipped_surfaces'][] = $surface['label'] . ' (no usable primary key or text columns)';
            return;
        }

        $scope = $this->getScopeClause($surface, $columns, 't', $projectId);
        if ($scope === null) {
            $scan['stats']['skipped_surfaces'][] = $surface['label'] . ' (not available in this REDCap schema)';
            return;
        }

        $candidateWhere = $this->getCandidateWhereClause($textColumns, 't');
        $selectColumns = array_merge($primaryKeys, $textColumns);
        $sql = 'SELECT ' . implode(', ', array_map(fn ($column) => 't.' . $this->identifier($column), $selectColumns))
            . ' FROM ' . $this->identifier($surface['table']) . ' t'
            . ' WHERE (' . $scope['sql'] . ') AND (' . $candidateWhere['sql'] . ')';
        $result = $this->query($sql, array_merge($scope['params'], $candidateWhere['params']));

        $surfaceStats = &$scan['stats']['surfaces'][$surface['id']];
        if (!isset($surfaceStats)) {
            $surfaceStats = [
                'label' => $surface['label'],
                'changed_cells' => 0,
                'changed_urls' => 0,
                'issues' => 0,
                'issues_by_reason' => [],
            ];
        }

        while ($row = $result->fetch_assoc()) {
            $keys = [];
            foreach ($primaryKeys as $key) {
                $keys[$key] = $row[$key];
            }

            foreach ($textColumns as $column) {
                $value = $row[$column] ?? '';
                if (!is_string($value) || !$this->containsPotentialUrl($value)) {
                    continue;
                }

                $upgraded = $this->upgradeText($value, $project);
                $scan['stats']['current_urls'] += $upgraded['current'];
                $scan['stats']['issues'] += $upgraded['issues'];
                $surfaceStats['issues'] += $upgraded['issues'];

                foreach ($upgraded['issues_by_reason'] as $reason => $count) {
                    $scan['stats']['issues_by_reason'][$reason] = ($scan['stats']['issues_by_reason'][$reason] ?? 0) + $count;
                    $surfaceStats['issues_by_reason'][$reason] = ($surfaceStats['issues_by_reason'][$reason] ?? 0) + $count;
                }

                foreach ($upgraded['states'] as $state => $count) {
                    $scan['stats']['by_hash_state'][$state] = ($scan['stats']['by_hash_state'][$state] ?? 0) + $count;
                }

                if ($upgraded['changed'] > 0 || $upgraded['issues'] > 0) {
                    $scan['detail_items'][] = [
                        'surface' => $surface['id'],
                        'column' => $column,
                        'keys' => $keys,
                        'checksum' => hash('sha256', $value),
                    ];
                }

                if ($upgraded['changed'] === 0) {
                    continue;
                }

                $scan['items'][] = [
                    'surface' => $surface['id'],
                    'column' => $column,
                    'keys' => $keys,
                    'checksum' => hash('sha256', $value),
                    'url_count' => $upgraded['changed'],
                ];
                $scan['stats']['changed_cells']++;
                $scan['stats']['changed_urls'] += $upgraded['changed'];
                $surfaceStats['changed_cells']++;
                $surfaceStats['changed_urls'] += $upgraded['changed'];
            }
        }
    }

    /**
     * Apply only cached cells whose contents have not changed since scanning.
     * This supplies optimistic locking without storing the original text.
     */
    private function applyCachedScan(array $project, $requestedScanId): array
    {
        $projectId = (int) $project['project_id'];
        $scan = $this->getCachedScan($projectId);
        if ($scan === null) {
            throw new \Exception('No cached scan is available. Run a new scan first.');
        }
        if (!is_string($requestedScanId) || !hash_equals($scan['id'], $requestedScanId)) {
            throw new \Exception('The scan result is no longer current. Run a new scan before applying changes.');
        }
        if ((int) $scan['project_status'] !== (int) $project['status'] || (int) $scan['draft_mode'] !== (int) $project['draft_mode']) {
            throw new \Exception('The project’s development/draft state changed since scanning. Run a new scan first.');
        }

        $surfaces = [];
        foreach ($this->getScanSurfaces($project) as $surface) {
            $surfaces[$surface['id']] = $surface;
        }

        $outcomes = [];
        $counts = ['updated_cells' => 0, 'updated_urls' => 0, 'skipped_changed' => 0, 'skipped_no_longer_needed' => 0, 'errors' => 0];
        foreach ($scan['items'] as $item) {
            $surface = $surfaces[$item['surface']] ?? null;
            if ($surface === null) {
                $outcomes[] = $this->outcome($item, 'skipped-surface-unavailable');
                $counts['errors']++;
                continue;
            }

            try {
                $outcome = $this->applyItem($surface, $item, $project);
                $outcomes[] = $outcome;
                if ($outcome['result'] === 'updated') {
                    $counts['updated_cells']++;
                    $counts['updated_urls'] += $item['url_count'];
                } elseif ($outcome['result'] === 'skipped-changed') {
                    $counts['skipped_changed']++;
                } elseif ($outcome['result'] === 'skipped-no-longer-needed') {
                    $counts['skipped_no_longer_needed']++;
                } else {
                    $counts['errors']++;
                }
            } catch (\Throwable $exception) {
                $outcomes[] = $this->outcome($item, 'error', $exception->getMessage());
                $counts['errors']++;
            }
        }

        $audit = $this->saveAuditFile($projectId, $scan, $outcomes);
        $this->log('Legacy URL repair batch completed', [
            'scan_id' => substr($scan['id'], 0, 16),
            'updated_cells' => $counts['updated_cells'],
            'updated_urls' => $counts['updated_urls'],
        ]);
        $this->setProjectSetting(self::SCAN_CACHE_KEY, null, $projectId);

        return [
            'scan_id' => $scan['id'],
            'counts' => $counts,
            'audit_doc_id' => $audit['doc_id'],
            'audit_error' => $audit['error'],
        ];
    }

    private function applyItem(array $surface, array $item, array $project): array
    {
        $columns = $this->getColumns($surface['table']);
        $primaryKeys = $this->getPrimaryKeys($columns);
        $column = $item['column'];
        if (!in_array($column, $this->getTextColumns($columns, array_merge($primaryKeys, $surface['exclude'] ?? [])), true)
            || array_keys($item['keys']) !== $primaryKeys
        ) {
            return $this->outcome($item, 'skipped-schema-changed');
        }

        $scope = $this->getScopeClause($surface, $columns, '', (int) $project['project_id']);
        if ($scope === null) {
            return $this->outcome($item, 'skipped-surface-unavailable');
        }

        $keyWhere = [];
        $keyParameters = [];
        foreach ($primaryKeys as $key) {
            $keyWhere[] = $this->identifier($key) . ' = ?';
            $keyParameters[] = $item['keys'][$key];
        }

        $selectSql = 'SELECT ' . $this->identifier($column)
            . ' FROM ' . $this->identifier($surface['table'])
            . ' WHERE ' . implode(' AND ', $keyWhere)
            . ' AND (' . $scope['sql'] . ')';
        $row = $this->query($selectSql, array_merge($keyParameters, $scope['params']))->fetch_assoc();
        if ($row === null || $row === false) {
            return $this->outcome($item, 'skipped-row-missing');
        }

        $oldValue = $row[$column];
        if (!is_string($oldValue) || !hash_equals($item['checksum'], hash('sha256', $oldValue))) {
            return $this->outcome($item, 'skipped-changed');
        }

        $upgraded = $this->upgradeText($oldValue, $project);
        if ($upgraded['changed'] === 0) {
            return $this->outcome($item, 'skipped-no-longer-needed');
        }

        $set = [$this->identifier($column) . ' = ?'];
        $parameters = [$upgraded['value']];
        if (($surface['reset_hash'] ?? false) && isset($columns['hash'])) {
            $set[] = $this->identifier('hash') . " = 'NoHash'";
        }
        if (($surface['reset_dashboard_cache'] ?? false)
            && isset($columns['cache_time'], $columns['cache_content'])) {
            $set[] = $this->identifier('cache_time') . ' = NULL';
            $set[] = $this->identifier('cache_content') . ' = NULL';
        }

        $updateSql = 'UPDATE ' . $this->identifier($surface['table'])
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . implode(' AND ', $keyWhere)
            . ' AND ' . $this->identifier($column) . ' = ?'
            . ' AND (' . $scope['sql'] . ')';
        $update = $this->createQuery();
        $update->add($updateSql, array_merge($parameters, $keyParameters, [$oldValue], $scope['params']));
        $update->execute();
        if ($update->affected_rows !== 1) {
            return $this->outcome($item, 'skipped-changed');
        }

        return $this->outcome($item, 'updated');
    }

    private function outcome(array $item, string $result, ?string $detail = null): array
    {
        return [
            'surface' => $item['surface'],
            'column' => $item['column'],
            'keys' => $item['keys'],
            'url_count' => $item['url_count'],
            'result' => $result,
            'detail' => $detail,
        ];
    }

    /**
     * Upgrade every supported URL in a single stored cell.
     */
    private function upgradeText(string $value, array $project): array
    {
        $result = [
            'value' => $value,
            'changed' => 0,
            'current' => 0,
            'issues' => 0,
            'issues_by_reason' => [],
            'states' => [],
            'matches' => [],
        ];

        $result['value'] = preg_replace_callback(self::URL_PATTERN, function (array $match) use (&$result, $project) {
            $urlResult = $this->upgradeUrl($match[0], $project);
            if ($urlResult['state'] === 'current') {
                $result['current']++;
                return $match[0];
            }
            if ($urlResult['state'] === 'issue') {
                $result['issues']++;
                $reason = $urlResult['reason'] ?? 'Unsupported URL format';
                $result['issues_by_reason'][$reason] = ($result['issues_by_reason'][$reason] ?? 0) + 1;
                $result['matches'][] = [
                    'state' => 'review',
                    'url' => $match[0],
                    'reason' => $reason,
                ];
                return $match[0];
            }
            if ($urlResult['state'] === 'ignored') {
                return $match[0];
            }

            $result['changed']++;
            $result['states'][$urlResult['state']] = ($result['states'][$urlResult['state']] ?? 0) + 1;
            $result['matches'][] = [
                'state' => 'repair',
                'url' => $match[0],
                'replacement' => $urlResult['url'],
            ];
            return $urlResult['url'];
        }, $value);

        return $result;
    }

    /**
     * This is the batch equivalent of ControlCenter/image_file_url_upgrader.php.
     */
    private function upgradeUrl(string $originalUrl, array $project): array
    {
        $url = trim(html_entity_decode($originalUrl, ENT_QUOTES | ENT_HTML5));
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['query'])) {
            return ['state' => 'issue', 'reason' => 'The URL cannot be parsed or has no query string'];
        }
        if (!$this->isConfiguredRedcapHost($parts)) {
            return ['state' => 'ignored'];
        }

        parse_str($parts['query'], $parameters);
        $providedHash = is_scalar($parameters['doc_id_hash'] ?? null) ? (string) $parameters['doc_id_hash'] : '';
        if (!$this->isLegacyDocIdHash($providedHash)) {
            return ['state' => 'ignored'];
        }

        $docId = $parameters['id'] ?? null;
        if (!$this->isInteger($docId)) {
            return ['state' => 'issue', 'reason' => 'No valid static document ID was found'];
        }
        $docId = (int) $docId;

        $path = urldecode($parts['path'] ?? '');
        $passthru = urldecode($parameters['__passthru'] ?? '');
        $isImage = $this->endsWith($path, 'DataEntry/image_view.php') || $passthru === 'DataEntry/image_view.php';
        $isDownload = $this->endsWith($path, 'DataEntry/file_download.php') || $passthru === 'DataEntry/file_download.php';
        if (!$isImage && !$isDownload) {
            return ['state' => 'issue', 'reason' => 'The URL is not a supported image or file endpoint'];
        }

        $document = $this->getDocument($docId);
        if ($document === false) {
            return ['state' => 'issue', 'reason' => 'The referenced document no longer exists'];
        }
        if ((int) $document['project_id'] !== (int) ($project['project_id'] ?? 0)) {
            return ['state' => 'issue', 'reason' => 'The referenced document belongs to a different project'];
        }

        $currentHash = \Files::docIdHash($docId, $document['__SALT__']);
        $legacyHash = \Files::docIdHashLegacy($docId, $document['__SALT__']);
        if (hash_equals($currentHash, $providedHash)) {
            return ['state' => 'current'];
        }
        if (!hash_equals($legacyHash, $providedHash)) {
            return ['state' => 'issue', 'reason' => 'The legacy document hash does not match the document ID'];
        }

        $parameters['doc_id_hash'] = $currentHash;
        if (isset($parameters['pid']) && $this->isInteger($parameters['pid'])) {
            $parameters['pid'] = $document['project_id'];
        }
        if ($isDownload && isset($parameters['doc_id_hash2'])) {
            $parameters['doc_id_hash2'] = base64_encode(encrypt(\Files::docIdHash($docId)));
        }
        if ($isDownload && isset($parameters['doc_version']) && $this->isInteger($parameters['doc_version'])) {
            $parameters['doc_version_hash'] = \Files::docIdHash($docId . 'v' . $parameters['doc_version']);
        }

        $parts['query'] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $upgradedUrl = $this->buildUrl($parts);
        if (stripos($originalUrl, '&amp;') !== false) {
            $upgradedUrl = str_replace('&', '&amp;', $upgradedUrl);
        }

        return ['state' => 'legacy', 'url' => $upgradedUrl];
    }

    private function getDocument(int $docId)
    {
        $documentKey = (string) $docId;
        if (array_key_exists($documentKey, $this->documentCache)) {
            return $this->documentCache[$documentKey];
        }

        $sql = 'SELECT e.doc_id, e.project_id, p.__SALT__'
            . ' FROM redcap_edocs_metadata e'
            . ' INNER JOIN redcap_projects p ON p.project_id = e.project_id'
            . ' WHERE e.doc_id = ?';
        $row = $this->query($sql, [$docId])->fetch_assoc();
        $this->documentCache[$documentKey] = $row ?: false;
        return $this->documentCache[$documentKey];
    }

    /**
     * Fixed table registry. Text columns are discovered at runtime so the
     * module remains compatible with minor schema additions between versions.
     */
    private function getScanSurfaces(array $project): array
    {
        $metadataTable = $this->getActiveMetadataTable($project);
        $multilanguageSuffix = $metadataTable === 'redcap_metadata_temp' ? '_temp' : '';
        $surfaces = [
            ['id' => 'surveys', 'label' => 'Survey settings', 'table' => 'redcap_surveys', 'scope' => 'direct'],
            ['id' => 'automated-invitations', 'label' => 'Automated survey invitations', 'table' => 'redcap_surveys_scheduler', 'scope' => 'survey'],
            ['id' => 'pending-survey-emails', 'label' => 'Pending survey invitations', 'table' => 'redcap_surveys_emails', 'scope' => 'survey', 'pending_only' => true],
            ['id' => 'alerts', 'label' => 'Alerts & Notifications', 'table' => 'redcap_alerts', 'scope' => 'direct'],
            ['id' => 'reports', 'label' => 'Reports', 'table' => 'redcap_reports', 'scope' => 'direct'],
            ['id' => 'project-dashboards', 'label' => 'Project dashboards', 'table' => 'redcap_project_dashboards', 'scope' => 'direct', 'reset_dashboard_cache' => true, 'exclude' => ['cache_content']],
            ['id' => 'record-dashboards', 'label' => 'Record status dashboards', 'table' => 'redcap_record_dashboards', 'scope' => 'direct'],
            ['id' => 'descriptive-popups', 'label' => 'Descriptive popups', 'table' => 'redcap_descriptive_popups', 'scope' => 'direct'],
            ['id' => 'econsent-settings', 'label' => 'e-Consent settings', 'table' => 'redcap_econsent', 'scope' => 'direct'],
            ['id' => 'econsent-forms', 'label' => 'e-Consent form settings', 'table' => 'redcap_econsent_forms', 'scope' => 'econsent'],
            ['id' => 'mycap-tasks', 'label' => 'MyCap tasks', 'table' => 'redcap_mycap_tasks', 'scope' => 'direct'],
            ['id' => 'data-quality-rules', 'label' => 'Data Quality rules', 'table' => 'redcap_data_quality_rules', 'scope' => 'direct'],
            ['id' => 'multilanguage-metadata', 'label' => 'Multi-Language metadata', 'table' => 'redcap_multilanguage_metadata' . $multilanguageSuffix, 'scope' => 'direct', 'reset_hash' => true],
            ['id' => 'multilanguage-config', 'label' => 'Multi-Language configuration', 'table' => 'redcap_multilanguage_config' . $multilanguageSuffix, 'scope' => 'direct', 'reset_hash' => true],
            ['id' => 'multilanguage-ui', 'label' => 'Multi-Language UI text', 'table' => 'redcap_multilanguage_ui' . $multilanguageSuffix, 'scope' => 'direct', 'reset_hash' => true],
        ];

        if ($metadataTable !== null) {
            array_unshift($surfaces, [
                'id' => 'active-metadata',
                'label' => $metadataTable === 'redcap_metadata_temp' ? 'Draft data dictionary' : 'Data dictionary',
                'table' => $metadataTable,
                'scope' => 'direct',
            ]);
        }

        return $surfaces;
    }

    /**
     * Physical tables used by the Control Center scanner. Data dictionary and
     * Multi-Language tables are split where Draft Mode changes the live table.
     */
    private function getControlCenterScanSurfaces(): array
    {
        return [
            [
                'id' => 'data-dictionary-active',
                'label' => 'Data dictionary (active table)',
                'table' => 'redcap_metadata',
                'scope' => 'direct',
                // This is read-only. Production repairs are still restricted
                // to redcap_metadata_temp while a project is in Draft Mode.
                'project_filter' => '(p.`status` = 0 OR p.`draft_mode` IS NULL OR p.`draft_mode` <> 1)',
            ],
            [
                'id' => 'data-dictionary-draft',
                'label' => 'Draft data dictionary (non-development projects in Draft Mode)',
                'table' => 'redcap_metadata_temp',
                'scope' => 'direct',
                'project_filter' => 'p.`status` <> 0 AND p.`draft_mode` = 1',
            ],
            ['id' => 'surveys', 'label' => 'Survey settings', 'table' => 'redcap_surveys', 'scope' => 'direct'],
            ['id' => 'automated-invitations', 'label' => 'Automated survey invitations', 'table' => 'redcap_surveys_scheduler', 'scope' => 'survey'],
            ['id' => 'pending-survey-emails', 'label' => 'Pending survey invitations', 'table' => 'redcap_surveys_emails', 'scope' => 'survey', 'pending_only' => true],
            ['id' => 'alerts', 'label' => 'Alerts & Notifications', 'table' => 'redcap_alerts', 'scope' => 'direct'],
            ['id' => 'reports', 'label' => 'Reports', 'table' => 'redcap_reports', 'scope' => 'direct'],
            ['id' => 'project-dashboards', 'label' => 'Project dashboards', 'table' => 'redcap_project_dashboards', 'scope' => 'direct', 'exclude' => ['cache_content']],
            ['id' => 'record-dashboards', 'label' => 'Record status dashboards', 'table' => 'redcap_record_dashboards', 'scope' => 'direct'],
            ['id' => 'descriptive-popups', 'label' => 'Descriptive popups', 'table' => 'redcap_descriptive_popups', 'scope' => 'direct'],
            ['id' => 'econsent-settings', 'label' => 'e-Consent settings', 'table' => 'redcap_econsent', 'scope' => 'direct'],
            ['id' => 'econsent-forms', 'label' => 'e-Consent form settings', 'table' => 'redcap_econsent_forms', 'scope' => 'econsent'],
            ['id' => 'mycap-tasks', 'label' => 'MyCap tasks', 'table' => 'redcap_mycap_tasks', 'scope' => 'direct'],
            ['id' => 'data-quality-rules', 'label' => 'Data Quality rules', 'table' => 'redcap_data_quality_rules', 'scope' => 'direct'],
            [
                'id' => 'multilanguage-metadata-live',
                'label' => 'Multi-Language metadata (active table)',
                'table' => 'redcap_multilanguage_metadata',
                'scope' => 'direct',
                'project_filter' => '(p.`status` = 0 OR p.`draft_mode` IS NULL OR p.`draft_mode` <> 1)',
            ],
            [
                'id' => 'multilanguage-metadata-draft',
                'label' => 'Multi-Language metadata (Draft Mode)',
                'table' => 'redcap_multilanguage_metadata_temp',
                'scope' => 'direct',
                'project_filter' => 'p.`status` <> 0 AND p.`draft_mode` = 1',
            ],
            [
                'id' => 'multilanguage-config-live',
                'label' => 'Multi-Language configuration (active table)',
                'table' => 'redcap_multilanguage_config',
                'scope' => 'direct',
                'project_filter' => '(p.`status` = 0 OR p.`draft_mode` IS NULL OR p.`draft_mode` <> 1)',
            ],
            [
                'id' => 'multilanguage-config-draft',
                'label' => 'Multi-Language configuration (Draft Mode)',
                'table' => 'redcap_multilanguage_config_temp',
                'scope' => 'direct',
                'project_filter' => 'p.`status` <> 0 AND p.`draft_mode` = 1',
            ],
            [
                'id' => 'multilanguage-ui-live',
                'label' => 'Multi-Language UI text (active table)',
                'table' => 'redcap_multilanguage_ui',
                'scope' => 'direct',
                'project_filter' => '(p.`status` = 0 OR p.`draft_mode` IS NULL OR p.`draft_mode` <> 1)',
            ],
            [
                'id' => 'multilanguage-ui-draft',
                'label' => 'Multi-Language UI text (Draft Mode)',
                'table' => 'redcap_multilanguage_ui_temp',
                'scope' => 'direct',
                'project_filter' => 'p.`status` <> 0 AND p.`draft_mode` = 1',
            ],
        ];
    }

    /**
     * Production projects never expose redcap_metadata as a write target.
     */
    private function getActiveMetadataTable(array $project): ?string
    {
        if ((int) $project['status'] === 0) {
            return 'redcap_metadata';
        }
        return (int) $project['draft_mode'] === 1 ? 'redcap_metadata_temp' : null;
    }

    private function getScopeClause(array $surface, array $columns, string $alias, int $projectId): ?array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        if ($surface['scope'] === 'direct') {
            if (!isset($columns['project_id'])) {
                return null;
            }
            return ['sql' => $prefix . $this->identifier('project_id') . ' = ?', 'params' => [$projectId]];
        }
        if ($surface['scope'] === 'survey') {
            if (!isset($columns['survey_id'])) {
                return null;
            }
            $sql = 'EXISTS (SELECT 1 FROM redcap_surveys s WHERE s.`survey_id` = '
                . $prefix . $this->identifier('survey_id') . ' AND s.`project_id` = ?)';
            if (($surface['pending_only'] ?? false) === true) {
                if (!isset($columns['email_sent'])) {
                    return null;
                }
                $sql .= ' AND ' . $prefix . $this->identifier('email_sent') . ' IS NULL';
            }
            return ['sql' => $sql, 'params' => [$projectId]];
        }
        if ($surface['scope'] === 'econsent') {
            if (!isset($columns['consent_id'])) {
                return null;
            }
            return [
                'sql' => 'EXISTS (SELECT 1 FROM redcap_econsent c WHERE c.`consent_id` = '
                    . $prefix . $this->identifier('consent_id') . ' AND c.`project_id` = ?)',
                'params' => [$projectId],
            ];
        }
        return null;
    }

    /**
     * Joins a physical surface to non-deleted projects for a system-wide scan.
     * Every SQL fragment originates in the fixed Control Center registry.
     */
    private function getControlCenterScopeClause(array $surface, array $columns): ?array
    {
        $projectWhere = 'p.`date_deleted` IS NULL';
        if (isset($surface['project_filter'])) {
            $projectWhere .= ' AND (' . $surface['project_filter'] . ')';
        }

        if ($surface['scope'] === 'direct') {
            if (!isset($columns['project_id'])) {
                return null;
            }
            return [
                'join' => ' INNER JOIN redcap_projects p ON p.`project_id` = t.`project_id`',
                'sql' => $projectWhere,
            ];
        }
        if ($surface['scope'] === 'survey') {
            if (!isset($columns['survey_id'])) {
                return null;
            }
            $sql = $projectWhere;
            if (($surface['pending_only'] ?? false) === true) {
                if (!isset($columns['email_sent'])) {
                    return null;
                }
                $sql .= ' AND t.`email_sent` IS NULL';
            }
            return [
                'join' => ' INNER JOIN redcap_surveys s ON s.`survey_id` = t.`survey_id`'
                    . ' INNER JOIN redcap_projects p ON p.`project_id` = s.`project_id`',
                'sql' => $sql,
            ];
        }
        if ($surface['scope'] === 'econsent') {
            if (!isset($columns['consent_id'])) {
                return null;
            }
            return [
                'join' => ' INNER JOIN redcap_econsent c ON c.`consent_id` = t.`consent_id`'
                    . ' INNER JOIN redcap_projects p ON p.`project_id` = c.`project_id`',
                'sql' => $projectWhere,
            ];
        }
        return null;
    }

    private function getCandidateWhereClause(array $columns, string $alias): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $legacyHash = 'doc_id_hash=' . str_repeat('_', 40);
        $endpoints = [
            'DataEntry/image_view.php',
            'DataEntry/file_download.php',
            'DataEntry%2Fimage_view.php',
            'DataEntry%2Ffile_download.php',
        ];
        // The delimiter immediately after the 40 wildcards prevents current,
        // longer hashes from passing the SQL prefilter.
        $hashEndings = ['&%', '&amp;%', '"%', "'%", '>%', '#%', ''];
        $patterns = [];
        foreach ($this->getConfiguredRedcapHosts() as $host) {
            foreach (['%://' . $host . '/%', '%://' . $host . ':%/%'] as $hostPrefix) {
                foreach ($endpoints as $endpoint) {
                    foreach ($hashEndings as $ending) {
                        $patterns[] = $hostPrefix . $endpoint . '%' . $legacyHash . $ending;
                    }
                }
            }
        }
        if ($patterns === []) {
            throw new \Exception('Could not determine the configured REDCap base or survey URL host.');
        }
        $conditions = [];
        $parameters = [];
        foreach ($columns as $column) {
            $conditions[] = '(' . implode(' OR ', array_fill(0, count($patterns), $prefix . $this->identifier($column) . ' LIKE ?')) . ')';
            array_push($parameters, ...$patterns);
        }
        return ['sql' => implode(' OR ', $conditions), 'params' => $parameters];
    }

    /** @return array<string, array<string, string>> */
    private function getColumns(string $table): array
    {
        $exists = $this->query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->fetch_assoc();
        if ($exists === null || $exists === false) {
            return [];
        }

        $columns = [];
        $result = $this->query('SHOW COLUMNS FROM ' . $this->identifier($table), []);
        while ($column = $result->fetch_assoc()) {
            $columns[$column['Field']] = $column;
        }
        return $columns;
    }

    /** @param array<string, array<string, string>> $columns */
    private function getPrimaryKeys(array $columns): array
    {
        $keys = [];
        foreach ($columns as $name => $column) {
            if (($column['Key'] ?? '') === 'PRI') {
                $keys[] = $name;
            }
        }
        return $keys;
    }

    /** @param array<string, array<string, string>> $columns */
    private function getTextColumns(array $columns, array $excluded): array
    {
        $textColumns = [];
        foreach ($columns as $name => $column) {
            if (in_array($name, $excluded, true)) {
                continue;
            }
            if (preg_match('/^(?:tiny|medium|long)?text|^(?:var)?char|^json/i', $column['Type'] ?? '')) {
                $textColumns[] = $name;
            }
        }
        return $textColumns;
    }

    private function getProject(int $projectId): ?array
    {
        $result = $this->query(
            'SELECT project_id, status, draft_mode, __SALT__ FROM redcap_projects WHERE project_id = ?',
            [$projectId]
        );
        $row = $result->fetch_assoc();
        return $row ?: null;
    }

    private function getCachedScan(int $projectId): ?array
    {
        $value = $this->getProjectSetting(self::SCAN_CACHE_KEY, $projectId);
        if (!is_string($value) || $value === '') {
            return null;
        }
        $scan = json_decode($value, true);
        if (!is_array($scan) || ($scan['project_id'] ?? null) != $projectId || !isset($scan['id'], $scan['items'], $scan['stats'])) {
            return null;
        }
        return $scan;
    }

    private function scanSummary(array $scan): array
    {
        return [
            'scan_id' => $scan['id'],
            'created_at' => $scan['created_at'],
            'project_status' => $scan['project_status'],
            'draft_mode' => $scan['draft_mode'],
            'stats' => $scan['stats'],
        ];
    }

    /**
     * Re-read a page of scanned cells to show URL-level details. The scan cache
     * never contains source text or URLs; each value is checked against its
     * scan-time fingerprint before it is returned to the browser.
     */
    private function getCachedScanDetails(array $project, $requestedScanId, $offset): array
    {
        $projectId = (int) $project['project_id'];
        $scan = $this->getCachedScan($projectId);
        if ($scan === null) {
            throw new \Exception('No cached scan is available. Run a new scan first.');
        }
        if (!is_string($requestedScanId) || !hash_equals($scan['id'], $requestedScanId)) {
            throw new \Exception('The scan result is no longer current. Run a new scan first.');
        }
        if ((int) $scan['project_status'] !== (int) $project['status'] || (int) $scan['draft_mode'] !== (int) $project['draft_mode']) {
            throw new \Exception('The project’s development/draft state changed since scanning. Run a new scan first.');
        }

        $detailItems = is_array($scan['detail_items'] ?? null) ? $scan['detail_items'] : [];
        $offset = $this->isInteger($offset) ? max(0, (int) $offset) : 0;
        $page = array_slice($detailItems, $offset, self::DETAIL_PAGE_SIZE);
        $surfaces = [];
        foreach ($this->getScanSurfaces($project) as $surface) {
            $surfaces[$surface['id']] = $surface;
        }

        $details = [];
        $staleCells = 0;
        foreach ($page as $item) {
            $surface = $surfaces[$item['surface'] ?? ''] ?? null;
            if ($surface === null) {
                $details[] = $this->staleDetail($item, null, 'The scanned surface is no longer available.');
                $staleCells++;
                continue;
            }

            $source = $this->getDetailItemSource($surface, $item, $project);
            if (!isset($source['value'])) {
                $details[] = $this->staleDetail($item, $surface, $source['reason']);
                $staleCells++;
                continue;
            }

            $upgraded = $this->upgradeText($source['value'], $project);
            foreach ($upgraded['matches'] as $match) {
                $details[] = [
                    'state' => $match['state'],
                    'surface' => $surface['label'],
                    'table' => $surface['table'],
                    'column' => $item['column'],
                    'keys' => $item['keys'],
                    'url' => $match['url'],
                    'replacement' => $match['replacement'] ?? null,
                    'reason' => $match['reason'] ?? null,
                ];
            }
        }

        $nextOffset = $offset + count($page);
        return [
            'scan_id' => $scan['id'],
            'details' => $details,
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'has_more' => $nextOffset < count($detailItems),
            'total_cells' => count($detailItems),
            'stale_cells' => $staleCells,
        ];
    }

    /**
     * Read one cached locator using the same schema and project-scope checks
     * used by repair. A changed value is never previewed as if it were scanned.
     */
    private function getDetailItemSource(array $surface, array $item, array $project): array
    {
        $columns = $this->getColumns($surface['table']);
        $primaryKeys = $this->getPrimaryKeys($columns);
        $column = $item['column'] ?? null;
        if (!is_string($column)
            || !is_array($item['keys'] ?? null)
            || !is_string($item['checksum'] ?? null)
            || !in_array($column, $this->getTextColumns($columns, array_merge($primaryKeys, $surface['exclude'] ?? [])), true)
            || array_keys($item['keys']) !== $primaryKeys
        ) {
            return ['reason' => 'The table schema or row identifier changed after the scan.'];
        }

        $scope = $this->getScopeClause($surface, $columns, '', (int) $project['project_id']);
        if ($scope === null) {
            return ['reason' => 'The scanned surface is no longer available for this project.'];
        }

        $keyWhere = [];
        $keyParameters = [];
        foreach ($primaryKeys as $key) {
            $keyWhere[] = $this->identifier($key) . ' = ?';
            $keyParameters[] = $item['keys'][$key];
        }
        $sql = 'SELECT ' . $this->identifier($column)
            . ' FROM ' . $this->identifier($surface['table'])
            . ' WHERE ' . implode(' AND ', $keyWhere)
            . ' AND (' . $scope['sql'] . ')';
        $row = $this->query($sql, array_merge($keyParameters, $scope['params']))->fetch_assoc();
        if ($row === null || $row === false) {
            return ['reason' => 'The scanned row no longer exists.'];
        }

        $value = $row[$column] ?? null;
        if (!is_string($value) || !hash_equals($item['checksum'], hash('sha256', $value))) {
            return ['reason' => 'This cell changed after the scan. Run a new scan for an updated preview.'];
        }
        return ['value' => $value];
    }

    private function staleDetail(array $item, ?array $surface, string $reason): array
    {
        return [
            'state' => 'stale',
            'surface' => $surface['label'] ?? ($item['surface'] ?? 'Unknown surface'),
            'table' => $surface['table'] ?? null,
            'column' => $item['column'] ?? null,
            'keys' => $item['keys'] ?? [],
            'url' => null,
            'replacement' => null,
            'reason' => $reason,
        ];
    }

    private function saveAuditFile(int $projectId, array $scan, array $outcomes): array
    {
        $filename = 'legacy-url-fixer_' . date('Ymd_His') . '_' . substr($scan['id'], 0, 12) . '.csv';
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $handle = fopen($path, 'x');
        if ($handle === false) {
            return ['doc_id' => null, 'error' => 'Could not create the audit file.'];
        }

        try {
            fputcsv($handle, ['scan_id', 'project_id', 'timestamp', 'surface', 'column', 'row_key', 'url_count', 'result', 'detail'], ',', '"', '');
            foreach ($outcomes as $outcome) {
                fputcsv($handle, [
                    $scan['id'],
                    $projectId,
                    date('c'),
                    $outcome['surface'],
                    $outcome['column'],
                    json_encode($outcome['keys']),
                    $outcome['url_count'],
                    $outcome['result'],
                    $outcome['detail'],
                ], ',', '"', '');
            }
        } finally {
            fclose($handle);
        }

        try {
            $docId = $this->saveFile($path, $projectId);
            if (!is_numeric($docId) || !\REDCap::addFileToRepository((int) $docId, $projectId, 'Legacy URL Fixer batch audit')) {
                return ['doc_id' => null, 'error' => 'The audit file could not be saved to the File Repository.'];
            }
            return ['doc_id' => (int) $docId, 'error' => null];
        } catch (\Throwable $exception) {
            return ['doc_id' => null, 'error' => $exception->getMessage()];
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function containsPotentialUrl(string $value): bool
    {
        return stripos($value, 'DataEntry/image_view.php') !== false
            || stripos($value, 'DataEntry/file_download.php') !== false
            || stripos($value, 'DataEntry%2Fimage_view.php') !== false
            || stripos($value, 'DataEntry%2Ffile_download.php') !== false;
    }

    private function isInteger($value): bool
    {
        return is_int($value) || (is_string($value) && $value !== '' && ctype_digit($value));
    }

    private function isLegacyDocIdHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{40}$/i', $hash) === 1;
    }

    /** @param array<string, mixed> $parts */
    private function isConfiguredRedcapHost(array $parts): bool
    {
        if (!isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        return in_array(strtolower($parts['host']), $this->getConfiguredRedcapHosts(), true);
    }

    /** @return string[] */
    private function getConfiguredRedcapHosts(): array
    {
        $hosts = [];
        foreach ([
            defined('APP_PATH_WEBROOT_FULL') ? APP_PATH_WEBROOT_FULL : null,
            defined('APP_PATH_SURVEY_FULL') ? APP_PATH_SURVEY_FULL : null,
        ] as $configuredUrl) {
            $host = is_string($configuredUrl) ? parse_url($configuredUrl, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }
        return array_values(array_unique($hosts));
    }

    private function endsWith(string $value, string $suffix): bool
    {
        return $suffix === '' || substr($value, -strlen($suffix)) === $suffix;
    }

    private function buildUrl(array $parts): string
    {
        $url = '';
        if (isset($parts['scheme'])) $url .= $parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $url .= $parts['user'];
            if (isset($parts['pass'])) $url .= ':' . $parts['pass'];
            $url .= '@';
        }
        if (isset($parts['host'])) $url .= $parts['host'];
        if (isset($parts['port'])) $url .= ':' . $parts['port'];
        $url .= $parts['path'] ?? '';
        if (isset($parts['query']) && $parts['query'] !== '') $url .= '?' . $parts['query'];
        if (isset($parts['fragment']) && $parts['fragment'] !== '') $url .= '#' . $parts['fragment'];
        return $url;
    }

    /**
     * Identifiers originate only from the fixed registry or SHOW COLUMNS.
     */
    private function identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
