<?php

namespace DE\RUB\SEG\LegacyURLFixerExternalModule;

use ExternalModules\ExternalModules;

/** Project-only, URL-level inventory and selected-link relocation. */
trait CrossProjectLinks
{
    /** Read-only inventory; never populates or changes a project's repair cache. */
    private function scanCrossProjectControlCenterSurface($surfaceId): array
    {
        $surfaces = array_column($this->getControlCenterScanSurfaces(), null, 'id');
        if (!is_string($surfaceId) || !isset($surfaces[$surfaceId])) {
            throw new \Exception('The requested cross-project scan surface is not available.');
        }
        $scan = ['created_at' => date('c'), 'status' => 'complete', 'message' => null, 'projects' => []];
        $resolved = $this->resolveSurface($surfaces[$surfaceId]);
        if ($resolved['surface'] === null) {
            $scan['status'] = 'unavailable';
            $scan['message'] = $resolved['reason'];
        } else {
            $surface = $resolved['surface'];
            $scope = $this->getControlCenterScopeClause($surface, false, 'all');
            $candidate = $this->getCrossProjectCandidateWhereClause($surface['columns'], 't');
            $columns = ['p.`project_id` AS `hosting_project_id`'];
            foreach ($surface['columns'] as $column) {
                $columns[] = 't.' . $this->identifier($column);
            }
            $result = $this->query(
                'SELECT ' . implode(', ', $columns) . ' FROM ' . $this->identifier($surface['table']) . ' t'
                . $scope['join'] . ' WHERE (' . $scope['sql'] . ') AND (' . $candidate['sql'] . ')',
                $candidate['params']
            );
            $this->documentCache = [];
            while ($row = $result->fetch_assoc()) {
                $projectId = (int) $row['hosting_project_id'];
                foreach ($surface['columns'] as $column) {
                    $value = $row[$column] ?? null;
                    if (!is_string($value) || !$this->containsPotentialUrl($value)) {
                        continue;
                    }
                    preg_match_all(self::URL_PATTERN, $value, $matches);
                    foreach ($matches[0] as $url) {
                        if ($this->crossProjectUrl($url, $projectId) !== null) {
                            $scan['projects'][$projectId] = ($scan['projects'][$projectId] ?? 0) + 1;
                        }
                    }
                }
            }
        }
        $encoded = json_encode($scan);
        if ($encoded === false || strlen($encoded) > self::MAX_SCAN_CACHE_BYTES) {
            throw new \Exception('The cross-project inventory is too large to cache safely.');
        }
        $this->framework->setSystemSetting(self::CROSS_PROJECT_CC_CACHE_PREFIX . $surfaceId, $encoded);
        return $this->getCrossProjectControlCenterStatus();
    }

    private function getCrossProjectControlCenterStatus(): array
    {
        $surfaces = [];
        $projects = [];
        foreach ($this->getControlCenterScanSurfaces() as $surface) {
            $raw = $this->framework->getSystemSetting(self::CROSS_PROJECT_CC_CACHE_PREFIX . $surface['id']);
            $scan = is_string($raw) ? json_decode($raw, true) : null;
            $scan = is_array($scan) ? $scan : [];
            $surfaces[] = [
                'id' => $surface['id'], 'label' => $surface['label'],
                'created_at' => $scan['created_at'] ?? null,
                'status' => $scan['status'] ?? 'not-scanned',
                'message' => $scan['message'] ?? null,
            ];
            foreach ($scan['projects'] ?? [] as $projectId => $count) {
                $projectId = (int) $projectId;
                if (!isset($projects[$projectId])) {
                    $projects[$projectId] = ['project_id' => $projectId, 'link_count' => 0, 'surfaces' => []];
                }
                $projects[$projectId]['link_count'] += (int) $count;
                $projects[$projectId]['surfaces'][] = $surface['label'];
            }
        }
        $rows = [];
        if ($projects) {
            // Resolve current titles and omit projects deleted since the scan.
            $result = $this->query(
                'SELECT project_id, app_title FROM redcap_projects WHERE date_deleted IS NULL AND project_id IN ('
                . implode(',', array_fill(0, count($projects), '?')) . ') ORDER BY project_id',
                array_keys($projects)
            );
            while ($row = $result->fetch_assoc()) {
                $project = $projects[(int) $row['project_id']];
                $project['title'] = $row['app_title'];
                $rows[] = $project;
            }
        }
        return ['surfaces' => $surfaces, 'projects' => $rows];
    }

    private function crossProjectCacheKey(): string
    {
        return self::CROSS_PROJECT_SCAN_KEY . '-' . substr(hash('sha256', strtolower((string) ExternalModules::getUsername())), 0, 24);
    }

    private function scanCrossProjectLinks(array $project): array
    {
        $projectId = (int) $project['project_id'];
        $scan = [
            'id' => bin2hex(random_bytes(16)),
            'project_id' => $projectId,
            'username' => strtolower((string) ExternalModules::getUsername()),
            'created_at' => date('c'),
            'project_status' => (int) $project['status'],
            'draft_mode' => (int) $project['draft_mode'],
            'items' => [],
        ];
        $rows = [];
        $skippedNoRights = 0;
        $skippedSurfaces = [];
        $ownerRights = [];
        $this->documentCache = [];

        foreach ($this->getScanSurfaces($project) as $surface) {
            $resolved = $this->resolveSurface($surface);
            if ($resolved['surface'] === null) {
                $skippedSurfaces[] = $surface['label'] . ': ' . $resolved['reason'];
                continue;
            }
            $surface = $resolved['surface'];
            $scope = $this->getScopeClause($surface, 't', $projectId);
            $candidate = $this->getCrossProjectCandidateWhereClause($surface['columns'], 't');
            $columns = array_merge($surface['keys'], $surface['columns']);
            $sql = 'SELECT ' . implode(', ', array_map(fn ($column) => 't.' . $this->identifier($column), $columns))
                . ' FROM ' . $this->identifier($surface['table']) . ' t'
                . ' WHERE (' . $scope['sql'] . ') AND (' . $candidate['sql'] . ')';
            $result = $this->query($sql, array_merge($scope['params'], $candidate['params']));
            while ($record = $result->fetch_assoc()) {
                $keys = [];
                foreach ($surface['keys'] as $key) {
                    $keys[$key] = $record[$key];
                }
                foreach ($surface['columns'] as $column) {
                    $value = $record[$column] ?? null;
                    if (!is_string($value) || !$this->containsPotentialUrl($value)) {
                        continue;
                    }
                    preg_match_all(self::URL_PATTERN, $value, $matches);
                    foreach ($matches[0] as $urlIndex => $url) {
                        $candidate = $this->crossProjectUrl($url, $projectId);
                        if ($candidate === null) {
                            continue;
                        }
                        $ownerId = $candidate['owner_project_id'];
                        if (!array_key_exists($ownerId, $ownerRights)) {
                            $ownerRights[$ownerId] = ExternalModules::hasDesignRights($ownerId);
                        }
                        if (!$ownerRights[$ownerId]) {
                            $skippedNoRights++;
                            continue;
                        }
                        $id = count($scan['items']);
                        $scan['items'][] = [
                            'id' => $id,
                            'surface' => $surface['id'],
                            'column' => $column,
                            'keys' => $keys,
                            'checksum' => hash('sha256', $value),
                            'url_index' => $urlIndex,
                            'url_checksum' => hash('sha256', $url),
                            'doc_id' => $candidate['doc_id'],
                            'owner_project_id' => $ownerId,
                        ];
                        $rows[] = [
                            'id' => $id,
                            'surface' => $surface['label'],
                            'column' => $column,
                            'keys' => $keys,
                            'owner_project_id' => $ownerId,
                            'doc_id' => $candidate['doc_id'],
                            'doc_name' => $candidate['doc_name'],
                            'hash_state' => $candidate['hash_state'],
                            'url' => $url,
                            'read_only' => (bool) ($surface['read_only'] ?? false),
                        ];
                    }
                }
            }
        }

        $encoded = json_encode($scan);
        if ($encoded === false || strlen($encoded) > self::MAX_SCAN_CACHE_BYTES) {
            throw new \Exception('The cross-project report is too large to cache safely.');
        }
        $this->framework->setProjectSetting($this->crossProjectCacheKey(), $encoded, $projectId);
        return [
            'scan_id' => $scan['id'],
            'created_at' => $scan['created_at'],
            'rows' => $rows,
            'skipped_no_rights' => $skippedNoRights,
            'skipped_surfaces' => $skippedSurfaces,
            'public_links_enabled' => ($GLOBALS['file_repository_allow_public_link'] ?? null) == '1',
        ];
    }

    /** Broad prefilter: the new report also includes links with no document hash. */
    private function getCrossProjectCandidateWhereClause(array $columns, string $alias): array
    {
        $patterns = [
            '%://%DataEntry/image_view.php%',
            '%://%DataEntry/file_download.php%',
            '%://%DataEntry%2Fimage_view.php%',
            '%://%DataEntry%2Ffile_download.php%',
        ];
        $conditions = [];
        $parameters = [];
        foreach ($columns as $column) {
            $conditions[] = '(' . implode(' OR ', array_fill(0, count($patterns), $alias . '.' . $this->identifier($column) . ' LIKE ?')) . ')';
            array_push($parameters, ...$patterns);
        }
        return ['sql' => implode(' OR ', $conditions), 'params' => $parameters];
    }

    /** Classify by document ownership, regardless of the supplied hash. */
    private function crossProjectUrl(string $originalUrl, int $projectId): ?array
    {
        $parts = parse_url(html_entity_decode($originalUrl, ENT_QUOTES | ENT_HTML5));
        if ($parts === false || !isset($parts['query']) || !$this->isConfiguredRedcapHost($parts)) {
            return null;
        }
        parse_str($parts['query'], $parameters);
        $id = $parameters['id'] ?? null;
        if (!$this->isInteger($id) || (int) $id < 1) {
            return null;
        }
        $path = urldecode($parts['path'] ?? '');
        $passthru = isset($parameters['__passthru']) && is_string($parameters['__passthru'])
            ? urldecode($parameters['__passthru']) : '';
        $isImage = $this->endsWith($path, 'DataEntry/image_view.php') || $passthru === 'DataEntry/image_view.php';
        $isDownload = $this->endsWith($path, 'DataEntry/file_download.php') || $passthru === 'DataEntry/file_download.php';
        if (!$isImage && !$isDownload) {
            return null;
        }
        $document = $this->getDocument((int) $id);
        if ($document === false || $document['project_id'] === null || $document['owner_project_exists'] === null
            || (int) $document['project_id'] === $projectId
            || $document['project_deleted'] !== null
            || !empty($document['delete_date']) || !empty($document['date_deleted_server'])) {
            return null;
        }
        $hash = $parameters['doc_id_hash'] ?? null;
        $state = 'mismatch or missing';
        if (is_string($hash) && $hash !== '') {
            if (hash_equals(\Files::docIdHash((int) $id, $document['__SALT__']), $hash)) {
                $state = 'current';
            } elseif (hash_equals(\Files::docIdHashLegacy((int) $id, $document['__SALT__']), $hash)) {
                $state = 'legacy';
            }
        }
        return [
            'doc_id' => (int) $id,
            'doc_name' => $document['doc_name'],
            'owner_project_id' => (int) $document['project_id'],
            'hash_state' => $state,
            'endpoint' => $isImage ? 'image_view' : 'file_download',
        ];
    }

    private function getCrossProjectScan(array $project, $scanId): array
    {
        $raw = $this->framework->getProjectSetting($this->crossProjectCacheKey(), (int) $project['project_id']);
        $scan = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($scan) || !is_string($scanId) || !isset($scan['id'], $scan['items'])
            || !hash_equals($scan['id'], $scanId)
            || (int) $scan['project_id'] !== (int) $project['project_id']
            || $scan['username'] !== strtolower((string) ExternalModules::getUsername())) {
            throw new \Exception('This report is no longer available to you. Run a new scan.');
        }
        if ((int) $scan['project_status'] !== (int) $project['status']
            || (int) $scan['draft_mode'] !== (int) $project['draft_mode']) {
            throw new \Exception('The project development or Draft Mode state changed. Run a new scan.');
        }
        return $scan;
    }

    private function applyCrossProjectLinks(array $project, array $payload): array
    {
        $scan = $this->getCrossProjectScan($project, $payload['scan_id'] ?? null);
        $mode = $payload['mode'] ?? null;
        if (!in_array($mode, ['relocate', 'public'], true)) {
            throw new \Exception('Select a supported link action.');
        }
        $selected = $payload['selected'] ?? null;
        if (!is_array($selected) || count($selected) < 1 || count($selected) > self::CROSS_PROJECT_BATCH_LIMIT) {
            throw new \Exception('Select 1 to ' . self::CROSS_PROJECT_BATCH_LIMIT . ' report rows.');
        }
        $folder = $mode === 'public' ? ($payload['folder'] ?? 'misc') : 'misc';
        if ($mode === 'public') {
            $this->validateCrossProjectFolder((int) $project['project_id'], $folder);
        }
        $selected = array_values(array_unique($selected));
        $items = [];
        foreach ($selected as $id) {
            if (!$this->isInteger($id) || !isset($scan['items'][(int) $id])) {
                throw new \Exception('A selected report row is invalid. Run a new scan.');
            }
            $items[] = $scan['items'][(int) $id];
        }
        $surfaces = [];
        foreach ($this->getScanSurfaces($project) as $surface) {
            $surfaces[$surface['id']] = $surface;
        }
        $groups = [];
        foreach ($items as $item) {
            $groupKey = $item['surface'] . '|' . $item['column'] . '|' . json_encode($item['keys']);
            $groups[$groupKey][] = $item;
        }
        $outcomes = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $surface = $surfaces[$first['surface']] ?? null;
            if ($surface === null || ($surface['read_only'] ?? false)) {
                foreach ($group as $item) $outcomes[] = $this->crossProjectOutcome($item, 'skipped-read-only');
                continue;
            }
            $source = $this->getDetailItemSource($surface, $first, $project);
            if (!isset($source['value'])) {
                foreach ($group as $item) $outcomes[] = $this->crossProjectOutcome($item, 'skipped-changed', $source['reason']);
                continue;
            }
            $value = $source['value'];
            preg_match_all(self::URL_PATTERN, $value, $matches);
            $replacements = [];
            $preparedOutcomes = [];
            foreach ($group as $item) {
                $index = (int) $item['url_index'];
                $url = $matches[0][$index] ?? null;
                $candidate = is_string($url) ? $this->crossProjectUrl($url, (int) $project['project_id']) : null;
                if ($candidate === null || !hash_equals($item['url_checksum'], hash('sha256', $url))
                    || $candidate['doc_id'] !== (int) $item['doc_id']
                    || $candidate['owner_project_id'] !== (int) $item['owner_project_id']
                    || !ExternalModules::hasDesignRights($candidate['owner_project_id'])) {
                    $outcomes[] = $this->crossProjectOutcome($item, 'skipped-changed-or-rights');
                    continue;
                }
                try {
                    $copy = $this->copyCrossProjectDocument($candidate['doc_id'], (int) $project['project_id'], $mode, $folder);
                    $replacements[$index] = $mode === 'public'
                        ? $this->crossProjectPublicUrl($copy['public_url'], $copy['doc_id'], $candidate['endpoint'], $url)
                        : $this->crossProjectLocalUrl($url, $copy['doc_id'], (int) $project['project_id']);
                    $preparedOutcomes[] = count($outcomes);
                    $outcomes[] = $this->crossProjectOutcome($item, 'prepared', 'New document ID ' . $copy['doc_id']);
                } catch (\Throwable $exception) {
                    $outcomes[] = $this->crossProjectOutcome($item, 'error', $exception->getMessage());
                }
            }
            if (!$replacements) continue;
            $urlIndex = -1;
            $newValue = preg_replace_callback(self::URL_PATTERN, function ($match) use (&$urlIndex, $replacements) {
                $urlIndex++;
                return $replacements[$urlIndex] ?? $match[0];
            }, $value);
            try {
                $written = is_string($newValue)
                    && $this->writeCrossProjectCell($surface, $first, $project, $value, $newValue);
                foreach ($preparedOutcomes as $outcomeIndex) {
                    $outcomes[$outcomeIndex]['result'] = $written ? 'updated' : 'skipped-changed-after-copy';
                    if (!$written) {
                        $outcomes[$outcomeIndex]['detail'] .= '; copied file remains in this project';
                    }
                }
            } catch (\Throwable $exception) {
                foreach ($preparedOutcomes as $outcomeIndex) {
                    $outcomes[$outcomeIndex]['result'] = 'error-after-copy';
                    $outcomes[$outcomeIndex]['detail'] .= '; link update failed: ' . $exception->getMessage();
                }
            }

        }
        $updated = count(array_filter($outcomes, fn ($outcome) => $outcome['result'] === 'updated'));
        $this->framework->setProjectSetting($this->crossProjectCacheKey(), '', (int) $project['project_id']);
        $audit = $this->saveAuditFile((int) $project['project_id'], $scan, $outcomes);
        $this->log('Cross-project link relocation batch', [
            'project_id' => (int) $project['project_id'],
            'mode' => $mode,
            'selected_count' => count($items),
            'updated_count' => $updated,
            'audit_doc_id' => $audit['doc_id'] ?? '',
            'audit_error' => $audit['error'] ?? '',
        ]);
        return ['updated' => $updated, 'selected' => count($items), 'outcomes' => $outcomes,
            'audit_doc_id' => $audit['doc_id'], 'audit_error' => $audit['error']];
    }

    private function crossProjectOutcome(array $item, string $result, ?string $detail = null): array
    {
        return [
            'id' => $item['id'] ?? null,
            'surface' => $item['surface'],
            'column' => $item['column'],
            'keys' => $item['keys'],
            'url_count' => 1,
            'result' => $result,
            'detail' => $detail,
        ];
    }

    private function validateCrossProjectFolder(int $projectId, $folder): void
    {
        if ($folder === 'misc') return;
        if (($GLOBALS['file_repository_allow_public_link'] ?? null) != '1') {
            throw new \Exception('Public File Repository sharing is disabled. Use Miscellaneous File Attachments.');
        }
        if (!$this->isInteger($folder) || (int) $folder < 1) {
            throw new \Exception('Select a valid File Repository folder.');
        }
        $row = $this->query(
            'SELECT folder_id, admin_only FROM redcap_docs_folders WHERE folder_id = ? AND project_id = ? AND deleted = 0',
            [(int) $folder, $projectId]
        )->fetch_assoc();
        if ($row === null || ((int) $row['admin_only'] === 1 && !$this->isSuperUser())
            || !\FileRepository::userHasFolderAccess((int) $folder)) {
            throw new \Exception('The chosen File Repository folder is no longer available to you.');
        }
    }

    private function copyCrossProjectDocument(int $oldId, int $projectId, string $mode, $folder): array
    {
        $newId = (int) \REDCap::copyFile($oldId, $projectId);
        if ($newId < 1) throw new \Exception('REDCap could not copy the file.');
        $misc = $mode === 'relocate' || $folder === 'misc';
        if (!\REDCap::addFileToRepository($newId, $projectId, 'Cross-project link relocation', $misc)) {
            \Files::deleteFileByDocId($newId, $projectId);
            throw new \Exception('The copied file could not be registered in the File Repository.');
        }
        $docs = $this->query('SELECT docs_id FROM redcap_docs_to_edocs WHERE doc_id = ?', [$newId])->fetch_assoc();
        if ($docs === null) throw new \Exception('The copied file has no File Repository mapping.');
        $docsId = (int) $docs['docs_id'];
        if ($misc) {
            $attachment = $this->query('SELECT 1 FROM redcap_docs_attachments WHERE docs_id = ?', [$docsId])->fetch_assoc();
            if ($attachment === null) throw new \Exception('The copied file was not registered as a miscellaneous attachment.');
        }
        if ($mode === 'public' && !$misc) {
            $this->query('INSERT INTO redcap_docs_folders_files (docs_id, folder_id) VALUES (?, ?)', [$docsId, (int) $folder]);
        }
        $publicUrl = $mode === 'public' ? \FileRepository::getPublicLink($docsId, $projectId) : null;
        if ($mode === 'public' && !is_string($publicUrl)) {
            throw new \Exception('REDCap could not create a public link for the copied file.');
        }
        return ['doc_id' => $newId, 'public_url' => $publicUrl];
    }

    private function crossProjectLocalUrl(string $originalUrl, int $newId, int $projectId): string
    {
        $parts = parse_url(html_entity_decode($originalUrl, ENT_QUOTES | ENT_HTML5));
        parse_str($parts['query'], $parameters);
        $path = urldecode($parts['path'] ?? '');
        $passthru = isset($parameters['__passthru']) && is_string($parameters['__passthru'])
            ? urldecode($parameters['__passthru']) : '';
        $download = $this->endsWith($path, 'DataEntry/file_download.php') || $passthru === 'DataEntry/file_download.php';
        // A public-file token belongs to the old repository entry. Switch to a
        // normal project URL rather than carrying that token to the new file.
        if (isset($parameters['__file'], $parameters['__passthru'])) {
            $endpoint = $download ? 'file_download' : 'image_view';
            $base = rtrim(APP_PATH_WEBROOT_FULL, '/') . '/DataEntry/' . $endpoint . '.php';
            $parameters = [];
            $parts = parse_url($base);
        }
        $parameters['pid'] = $projectId;
        $parameters['id'] = $newId;
        $parameters['doc_id_hash'] = \Files::docIdHash($newId);
        if ($download) $parameters['type'] = 'attachment';
        unset($parameters['__file'], $parameters['__email'], $parameters['__report'],
            $parameters['download'], $parameters['doc_id_hash2'], $parameters['doc_version'],
            $parameters['doc_version_hash'], $parameters['origin'], $parameters['signature']);
        $parts['query'] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        return $this->crossProjectEscapeUrl($this->buildUrl($parts), $originalUrl);
    }

    private function crossProjectPublicUrl(string $publicUrl, int $newId, string $endpoint, string $originalUrl): string
    {
        $url = $publicUrl . '&__passthru=DataEntry%2F' . $endpoint . '.php'
            . '&doc_id_hash=' . rawurlencode(\Files::docIdHash($newId)) . '&id=' . $newId;
        return $this->crossProjectEscapeUrl($url, $originalUrl);
    }

    private function crossProjectEscapeUrl(string $url, string $originalUrl): string
    {
        return stripos($originalUrl, '&amp;') === false ? $url : str_replace('&', '&amp;', $url);
    }

    private function writeCrossProjectCell(array $surface, array $item, array $project, string $old, string $new): bool
    {
        $resolved = $this->resolveSurface($surface);
        if ($resolved['surface'] === null || $new === $old) return false;
        $surface = $resolved['surface'];
        $column = $this->getCachedSurfaceColumn($surface, $item);
        if ($column === null || !$this->hasExpectedKeys($surface, $item['keys'])) return false;
        $scope = $this->getScopeClause($surface, '', (int) $project['project_id']);
        $keys = $this->getKeyWhereClause($surface, $item['keys']);
        $set = [$this->identifier($column) . ' = ?'];
        if (($surface['reset_hash'] ?? false) === true) $set[] = '`hash` = \'NoHash\'';
        if (($surface['reset_dashboard_cache'] ?? false) === true) {
            $set[] = '`cache_time` = NULL';
            $set[] = '`cache_content` = NULL';
        }
        $sql = 'UPDATE ' . $this->identifier($surface['table']) . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $keys['sql'] . ' AND CAST(' . $this->identifier($column) . ' AS BINARY) = CAST(? AS BINARY)'
            . ' AND (' . $scope['sql'] . ')';
        $this->query('START TRANSACTION', []);
        try {
            $update = $this->createQuery();
            $update->add($sql, array_merge([$new], $keys['params'], [$old], $scope['params']));
            $update->execute();
            if ($update->affected_rows !== 1) {
                $this->query('ROLLBACK', []);
                return false;
            }
            $this->query('COMMIT', []);
            return true;
        } catch (\Throwable $exception) {
            $this->query('ROLLBACK', []);
            throw $exception;
        }
    }
}
