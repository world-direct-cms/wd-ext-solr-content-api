<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Service;

use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use WorldDirect\SolrContentApi\Domain\Repository\ApiKeyRepository;

final class ApiKeyVerificationService
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly PasswordHashFactory $passwordHashFactory,
    ) {}

    /** @return array{uid: int, label: string, api_key: string}|null */
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
