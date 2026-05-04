<?php

/**
 * Client Manager (V2.0)
 * 
 * Handles creation and management of clients and their configs.
 * Replaces the old approach of manual config assignment.
 */
class ClientManager
{
    /** @var PDO */
    private $db;

    /** @var ClientIdGenerator */
    private $idGenerator;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->idGenerator = new ClientIdGenerator($db);
    }

    /**
     * Create a new client and auto-generate configs for all protocols on assigned servers.
     * 
     * @param array $data Client data (name, email, phone, manager_id, expires_at, traffic_limit_mb, notes, server_ids[])
     * @return string The generated client_id
     */
    public function createClient(array $data): string
    {
        $clientId = $this->idGenerator->generate();

        $this->db->beginTransaction();

        try {
            // 1. Insert client
            $stmt = $this->db->prepare('
                INSERT INTO clients (client_id, manager_id, name, email, phone, expires_at, traffic_limit_mb, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $clientId,
                $data['manager_id'],
                $data['name'],
                $data['email'] ?? null,
                $data['phone'] ?? null,
                $data['expires_at'] ?? null,
                $data['traffic_limit_mb'] ?? null,
                $data['notes'] ?? null,
            ]);

            // 2. Auto-generate configs for specified servers (for all their protocols)
            $serverIds = $data['server_ids'] ?? [];
            foreach ($serverIds as $serverId) {
                $this->generateConfigsForServer($clientId, (int)$serverId);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $clientId;
    }

    /**
     * Generate config records for all protocols on a given server for a client.
     * Called both on client creation and when new protocols are added to a server.
     */
    public function generateConfigsForServer(string $clientId, int $serverId): void
    {
        // Get all installed protocols from vpn_servers
        // Protocol info is stored in awg_params JSON and container_name
        $stmt = $this->db->prepare('
            SELECT id, container_name, awg_params 
            FROM vpn_servers 
            WHERE id = ?
        ');
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$server) {
            return;
        }

        // Determine protocols from server configuration
        $protocols = $this->getProtocolsFromServer($server);

        foreach ($protocols as $protocol) {
            // Skip if already exists (unique constraint)
            $check = $this->db->prepare('
                SELECT 1 FROM client_configs 
                WHERE client_id = ? AND server_id = ? AND protocol = ?
            ');
            $check->execute([$clientId, $serverId, $protocol]);
            if ($check->fetch()) {
                continue;
            }

            // Generate config payload and QR code
            $configPayload = $this->generateConfigPayload($clientId, $server, $protocol);
            $configQr = $this->generateQrCode($configPayload);
            $configHash = hash('sha256', $configPayload);

            $insert = $this->db->prepare('
                INSERT INTO client_configs (client_id, server_id, protocol, config_payload, config_qr, config_hash)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $insert->execute([
                $clientId,
                $serverId,
                $protocol,
                $configPayload,
                $configQr,
                $configHash,
            ]);
        }
    }

    /**
     * Remove configs for a specific protocol from all clients on a server.
     * Called when a protocol is removed from a server.
     */
    public function removeConfigsForProtocol(int $serverId, string $protocol): void
    {
        $stmt = $this->db->prepare('
            DELETE FROM client_configs 
            WHERE server_id = ? AND protocol = ?
        ');
        $stmt->execute([$serverId, $protocol]);
    }

    /**
     * Get protocols installed on a server based on its configuration.
     */
    private function getProtocolsFromServer(array $server): array
    {
        $protocols = [];

        // Container name indicates primary protocol
        if (strpos($server['container_name'] ?? '', 'awg') !== false) {
            $protocols[] = 'amnezia-wg';
        } elseif (strpos($server['container_name'] ?? '', 'wireguard') !== false || strpos($server['container_name'] ?? '', 'wg') !== false) {
            $protocols[] = 'wireguard';
        }

        // Check awg_params for additional protocols
        $params = json_decode($server['awg_params'] ?? '{}', true);
        if (isset($params['protocols']) && is_array($params['protocols'])) {
            $protocols = array_merge($protocols, $params['protocols']);
        }

        // Default fallback
        if (empty($protocols)) {
            $protocols[] = 'wireguard';
        }

        return array_unique($protocols);
    }

    /**
     * Generate config payload for a client on a specific protocol.
     * TODO: Implement actual config generation based on protocol type.
     */
    private function generateConfigPayload(string $clientId, array $server, string $protocol): string
    {
        // Placeholder – actual config generation logic goes here
        return json_encode([
            'client_id' => $clientId,
            'server_id' => $server['id'],
            'protocol' => $protocol,
            'host' => $server['host'] ?? '',
            'port' => $server['vpn_port'] ?? 51820,
            'generated_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Generate QR code from config payload.
     */
    private function generateQrCode(string $payload): string
    {
        // Check if QrUtil exists
        if (class_exists('QrUtil')) {
            return QrUtil::generateBase64($payload);
        }
        // Fallback: store payload as-is (QR generation happens on converter site)
        return base64_encode($payload);
    }

    /**
     * Suspend a client (set is_active = 0).
     */
    public function suspend(string $clientId): void
    {
        $stmt = $this->db->prepare('UPDATE clients SET is_active = 0, updated_at = NOW() WHERE client_id = ?');
        $stmt->execute([$clientId]);
    }

    /**
     * Activate a client (set is_active = 1).
     */
    public function activate(string $clientId): void
    {
        $stmt = $this->db->prepare('UPDATE clients SET is_active = 1, updated_at = NOW() WHERE client_id = ?');
        $stmt->execute([$clientId]);
    }

    /**
     * Delete a client and all associated configs.
     */
    public function delete(string $clientId): void
    {
        $stmt = $this->db->prepare('DELETE FROM clients WHERE client_id = ?');
        $stmt->execute([$clientId]);
    }

    /**
     * Get client by ID with all configs.
     */
    public function getClientWithConfigs(string $clientId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT cc.*, vs.name AS server_name, vs.host AS server_host 
            FROM client_configs cc
            JOIN vpn_servers vs ON cc.server_id = vs.id
            WHERE cc.client_id = ?
        ');
        $stmt->execute([$clientId]);
        $client['configs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $client;
    }

    /**
     * Get all clients for a manager.
     */
    public function getClientsByManager(int $managerId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM clients 
            WHERE manager_id = ? 
            ORDER BY created_at DESC
        ');
        $stmt->execute([$managerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get servers assigned to a manager (via client configs).
     */
    public function getManagerServers(int $managerId): array
    {
        $stmt = $this->db->prepare('
            SELECT DISTINCT vs.*
            FROM vpn_servers vs
            JOIN client_configs cc ON vs.id = cc.server_id
            JOIN clients c ON cc.client_id = c.client_id
            WHERE c.manager_id = ?
        ');
        $stmt->execute([$managerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
