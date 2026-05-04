<?php

/**
 * Client Controller (V2.0)
 * 
 * Handles all manager requests related to client management.
 * Used by Router to process HTTP requests.
 */
class ClientController
{
    /** @var PDO */
    private $db;

    /** @var ClientManager */
    private $clientManager;

    /** @var Auth */
    private $auth;

    public function __construct(PDO $db, Auth $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
        $this->clientManager = new ClientManager($db);
    }

    /**
     * Create a new client (POST /manager/clients/create).
     */
    public function create(array $postData): array
    {
        // Check manager authentication
        $manager = $this->auth->getCurrentUser();
        if (!$manager || $manager['role'] !== 'manager') {
            return ['error' => 'Unauthorized', 'code' => 403];
        }

        // Validate required fields
        if (empty($postData['name'])) {
            return ['error' => 'Name is required', 'code' => 400];
        }

        // Get servers from manager's scope
        $serverIds = $postData['server_ids'] ?? [];
        if (empty($serverIds)) {
            // If no servers specified, get all servers available to this manager
            // (through admin assignment – to be implemented in admin panel)
            $stmt = $this->db->prepare('
                SELECT DISTINCT vs.id 
                FROM vpn_servers vs 
                WHERE vs.user_id = ? 
                AND vs.status = ?
            ');
            $stmt->execute([$manager['id'], 'active']);
            $serverIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        try {
            $clientId = $this->clientManager->createClient([
                'manager_id'   => $manager['id'],
                'name'         => trim($postData['name']),
                'email'        => $postData['email'] ?? null,
                'phone'        => $postData['phone'] ?? null,
                'expires_at'   => $postData['expires_at'] ?? null,
                'traffic_limit_mb' => isset($postData['traffic_limit_mb']) ? (int)$postData['traffic_limit_mb'] : null,
                'notes'        => $postData['notes'] ?? null,
                'server_ids'   => $serverIds,
            ]);

            return [
                'success' => true,
                'client_id' => $clientId,
                'message' => 'Client created successfully',
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to create client: ' . $e->getMessage(), 'code' => 500];
        }
    }

    /**
     * Suspend a client (POST /manager/clients/suspend).
     */
    public function suspend(string $clientId): array
    {
        $manager = $this->auth->getCurrentUser();
        if (!$manager || $manager['role'] !== 'manager') {
            return ['error' => 'Unauthorized', 'code' => 403];
        }

        // Verify client belongs to this manager
        if (!$this->clientBelongsToManager($clientId, $manager['id'])) {
            return ['error' => 'Client not found', 'code' => 404];
        }

        $this->clientManager->suspend($clientId);

        return ['success' => true, 'message' => 'Client suspended'];
    }

    /**
     * Activate a client (POST /manager/clients/activate).
     */
    public function activate(string $clientId): array
    {
        $manager = $this->auth->getCurrentUser();
        if (!$manager || $manager['role'] !== 'manager') {
            return ['error' => 'Unauthorized', 'code' => 403];
        }

        if (!$this->clientBelongsToManager($clientId, $manager['id'])) {
            return ['error' => 'Client not found', 'code' => 404];
        }

        $this->clientManager->activate($clientId);

        return ['success' => true, 'message' => 'Client activated'];
    }

    /**
     * Delete a client (POST /manager/clients/delete).
     */
    public function delete(string $clientId): array
    {
        $manager = $this->auth->getCurrentUser();
        if (!$manager || $manager['role'] !== 'manager') {
            return ['error' => 'Unauthorized', 'code' => 403];
        }

        if (!$this->clientBelongsToManager($clientId, $manager['id'])) {
            return ['error' => 'Client not found', 'code' => 404];
        }

        $this->clientManager->delete($clientId);

        return ['success' => true, 'message' => 'Client deleted'];
    }

    /**
     * Get client details (GET /manager/clients/{clientId}).
     */
    public function view(string $clientId): array
    {
        $manager = $this->auth->getCurrentUser();
        if (!$manager || $manager['role'] !== 'manager') {
            return ['error' => 'Unauthorized', 'code' => 403];
        }

        if (!$this->clientBelongsToManager($clientId, $manager['id'])) {
            return ['error' => 'Client not found', 'code' => 404];
        }

        $client = $this->clientManager->getClientWithConfigs($clientId);
        if (!$client) {
            return ['error' => 'Client not found', 'code' => 404];
        }

        return ['success' => true, 'client' => $client];
    }

    /**
     * List all clients for the current manager (GET /manager/clients).
     */
    public function list(): array
    {
        $manager = $this->auth->getCurrentUser();
        if (!$manager || $manager['role'] !== 'manager') {
            return ['error' => 'Unauthorized', 'code' => 403];
        }

        $clients = $this->clientManager->getClientsByManager($manager['id']);

        return ['success' => true, 'clients' => $clients];
    }

    /**
     * Check if a client belongs to a specific manager.
     */
    private function clientBelongsToManager(string $clientId, int $managerId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM clients WHERE client_id = ? AND manager_id = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $managerId]);
        return (bool) $stmt->fetch();
    }
}
