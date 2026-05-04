<?php

/**
 * Client ID Generator (V2.0)
 * 
 * Generates unique 16-character IDs in format: AAAAXXXXXXXXXXXX
 * - First 4 characters: uppercase Latin letters
 * - Last 12 characters: digits
 * - Guaranteed unique within the database
 */
class ClientIdGenerator
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a unique Client ID
     * 
     * @return string 16-char ID (AAAAXXXXXXXXXXXX)
     */
    public function generate(): string
    {
        do {
            $id = $this->generateOne();
        } while ($this->exists($id));

        return $id;
    }

    /**
     * Generate a single candidate ID
     */
    private function generateOne(): string
    {
        $letters = '';
        // 4 uppercase letters (excluding easily confused: I, O)
        $allowedLetters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        for ($i = 0; $i < 4; $i++) {
            $letters .= $allowedLetters[random_int(0, strlen($allowedLetters) - 1)];
        }

        // 12 digits
        $digits = '';
        for ($i = 0; $i < 12; $i++) {
            $digits .= random_int(0, 9);
        }

        return $letters . $digits;
    }

    /**
     * Check if ID already exists in database
     */
    private function exists(string $clientId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM clients WHERE client_id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        return (bool) $stmt->fetch();
    }
    public static function validate(string $clientId): bool
    {
        return (bool) preg_match('/^[A-Z]{4}[0-9]{12}$/', $clientId);
    }
}
