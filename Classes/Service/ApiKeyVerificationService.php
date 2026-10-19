<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Service;

use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use WorldDirect\SolrContentApi\Domain\Repository\ApiKeyRepository;

/**
 * Authenticates API keys against active records using TYPO3 password hashing.
 */
final class ApiKeyVerificationService
{
    /**
     * Initializes access to stored API keys and TYPO3's password hash handlers.
     *
     * @param ApiKeyRepository $apiKeyRepository Repository supplying active API-key records.
     * @param PasswordHashFactory $passwordHashFactory Factory resolving the handler for each stored hash.
     */
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly PasswordHashFactory $passwordHashFactory,
    ) {}

    /**
     * Checks a trimmed candidate against all active keys, skipping invalid hashes.
     *
     * @param string|null $candidate Plaintext API key from the request, or null when absent.
     * @return array{uid: int, label: string, api_key: string}|null First matching record, or null if none matches.
     */
    public function verify(?string $candidate): ?array
    {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return null;
        }

        $match = null;
        foreach ($this->apiKeyRepository->findActive() as $apiKey) {
            try {
                $passwordHash = $this->passwordHashFactory->get($apiKey['api_key'], 'BE');
                if ($passwordHash->checkPassword($candidate, $apiKey['api_key']) && $match === null) {
                    $match = $apiKey;
                }
            } catch (InvalidPasswordHashException) {
                continue;
            }
        }

        return $match;
    }
}
