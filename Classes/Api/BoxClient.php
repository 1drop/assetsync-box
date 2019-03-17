<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2019 Hans Hoechtl <hhoechtl@1drop.de>
 *  All rights reserved
 ***************************************************************/
namespace Onedrop\AssetSync\Box\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Request;
use Neos\Flow\Log\PsrSystemLoggerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Onedrop\AssetSync\Box\Exception\MissingOAuthException;
use Psr\Log\LogLevel;

final class BoxClient
{
    private static $apiBaseUri = 'https://api.box.com/2.0';
    /**
     * @var string
     */
    protected $devToken = '';
    /**
     * @var string
     */
    protected $clientId;
    /**
     * @var string
     */
    protected $clientSecret;
    /**
     * @var string
     */
    protected $sourceIdentifier;
    /**
     * @var PsrSystemLoggerInterface
     * @Flow\Inject()
     */
    protected $systemLogger;
    /**
     * @var UriBuilder
     * @Flow\Inject()
     */
    protected $uriBuilder;
    /**
     * @var StringFrontend
     */
    protected $tokenCache;
    /**
     * @var int
     */
    protected $batchSize = 100;

    /**
     * @return Client
     */
    private function getClient(): Client
    {
        // Necessary or systemLogger is a DependencyProxy object
        $this->systemLogger->debug('New Guzzle client');
        /*$stack = HandlerStack::create();
        $stack->push(
            Middleware::log(
                $this->systemLogger,
                new MessageFormatter('{req_body} - {res_body}'),
                LogLevel::DEBUG
            )
        );
        return new Client(['handler' => $stack]);*/
        return new Client();
    }

    /**
     * Check if the box.com application has already been authorized by the user
     * for OAuth2
     *
     * @throws MissingOAuthException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    private function checkAuthorization()
    {
        if (!$this->tokenCache->has($this->sourceIdentifier . '__auth-token')) {
            $this->uriBuilder->setRequest(new ActionRequest(Request::createFromEnvironment()));
            $authUri = $this->uriBuilder
                ->setCreateAbsoluteUri(true)
                ->uriFor(
                    'requestToken',
                    ['assetSourceIdentifier' => $this->sourceIdentifier],
                    'Authenticate',
                    'Onedrop.AssetSync.Box'
                );
            throw new MissingOAuthException('You must authorize box.com access: ' . $authUri);
        }
    }

    /**
     * @throws MissingOAuthException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @return mixed
     */
    private function newAccessToken()
    {
        if (!empty($this->devToken)) {
            return $this->devToken;
        }
        $this->checkAuthorization();
        $authTokenCacheIdentifier = $this->sourceIdentifier . '__auth-token';
        $refreshTokenCacheIdentifier = $this->sourceIdentifier . '__refresh-token';
        $accessTokenCacheIdentifier = $this->sourceIdentifier . '__access-token';

        try {
            // On first authorization we have an auth code
            $grantType = 'authorization_code';
            $codeType = 'code';
            $code = $this->tokenCache->get($authTokenCacheIdentifier);
            // The following authorizations are done with a refresh token
            if ($this->tokenCache->has($refreshTokenCacheIdentifier)) {
                $code = $this->tokenCache->get($refreshTokenCacheIdentifier);
                $grantType = $codeType = 'refresh_token';
            }
            $responseData = $this->getClient()->request('POST', 'https://api.box.com/oauth2/token', [
                'form_params' => [
                    'grant_type'    => $grantType,
                    $codeType       => $code,
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ])->getBody()->__toString();
            $response = \GuzzleHttp\json_decode($responseData, true);
            list('access_token' => $accessToken, 'expires_in' => $expire, 'refresh_token' => $refreshToken) = $response;
            $this->tokenCache->set($accessTokenCacheIdentifier, $accessToken, [], $expire);
            $this->tokenCache->set($refreshTokenCacheIdentifier, $refreshToken);
            return $accessToken;
        } catch (RequestException $e) {
            $this->systemLogger->critical('Could not get access token. Revoking existing token.', ['error' => $e->getMessage()]);
            $this->tokenCache->remove($authTokenCacheIdentifier);
            $this->tokenCache->remove($accessTokenCacheIdentifier);
            $this->checkAuthorization();
        }
        return null;
    }

    /**
     * @param  array                                                       $query
     * @throws MissingOAuthException
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @return array
     */
    private function buildRequest($query = []): array
    {
        $accessToken = '';
        $accessTokenCacheIdentifier = $this->sourceIdentifier . '__access-token';
        try {
            if ($this->tokenCache->has($accessTokenCacheIdentifier)) {
                $accessToken = $this->tokenCache->get($accessTokenCacheIdentifier);
            } else {
                $accessToken = $this->newAccessToken();
            }
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            $this->systemLogger->error('Request of access token returned an error', ['error' => $e->getMessage()]);
        }
        $request = [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'allow_redirects' => true,
            'timeout'         => 2000,
            'http_errors'     => true,
        ];
        if (!empty($query)) {
            $request['query'] = $query;
        }
        return $request;
    }

    /**
     * @param  int                                                         $folderId
     * @throws MissingOAuthException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @return array
     */
    public function getFolderInfo(int $folderId)
    {
        return \GuzzleHttp\json_decode(
            $this->getClient()->request('GET', self::$apiBaseUri . '/folders/' . $folderId, $this->buildRequest())
                ->getBody()
                ->__toString(),
            true
        );
    }

    /**
     * @param  int                                                         $fileId
     * @throws MissingOAuthException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @return \Psr\Http\Message\StreamInterface
     */
    public function getFile(int $fileId)
    {
        return $this->getClient()
            ->request('GET', self::$apiBaseUri . '/files/' . $fileId . '/content', $this->buildRequest())
            ->getBody();
    }

    /**
     * @param  int                                                         $folderId
     * @param  int                                                         $limit
     * @param  int                                                         $offset
     * @throws MissingOAuthException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @return array
     */
    public function getFolderItems(int $folderId, $limit = 100, $offset = 0)
    {
        return \GuzzleHttp\json_decode(
            $this->getClient()
                ->request(
                'GET',
                self::$apiBaseUri . '/folders/' . $folderId . '/items',
                $this->buildRequest([
                    'limit'  => $limit,
                    'offset' => $offset,
                    'sort'   => 'date',
                    'fields' => 'name,created_at,modified_at,size',
                ])
            )->getBody()->__toString(),
            true
        );
    }

    /**
     * @param  int                                                         $folderId
     * @throws MissingOAuthException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @return array
     */
    public function getFolderItemsRecursive(int $folderId)
    {
        $allFolderItems = $this->getAllFolderItems($folderId);
        while (($idxFirstFolder = $this->getFirstFolderPosition($allFolderItems)) !== false) {
            $currentFolder = $allFolderItems[$idxFirstFolder];
            unset($allFolderItems[$idxFirstFolder]);
            $folderItems = array_map(function ($item) use ($currentFolder) {
                $item['folder'] = $currentFolder;
                $item['name'] = $currentFolder['name'] . '|' . $item['name'];
                return $item;
            }, $this->getAllFolderItems($currentFolder['id']));
            $allFolderItems = array_merge($allFolderItems, $folderItems);
        }
        return $allFolderItems;
    }

    /**
     * @param  int                                                         $folderId
     * @throws MissingOAuthException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @return array
     */
    protected function getAllFolderItems(int $folderId): array
    {
        $itemCount = (int)$this->getFolderItems($folderId, 0, 0)['total_count'];
        $offset = 0;
        $items = [];
        while ($offset < $itemCount) {
            $items = array_merge($items, $this->getFolderItems($folderId, $this->batchSize, $offset)['entries']);
            $offset += $this->batchSize;
        }
        return $items;
    }

    /**
     * @param  array    $items
     * @return bool|int
     */
    protected function getFirstFolderPosition(array $items)
    {
        foreach ($items as $idx => $item) {
            if ($item['type'] === 'folder') {
                return $idx;
            }
        }
        return false;
    }

    /**
     * @param string $clientId
     */
    public function setClientId(string $clientId): void
    {
        $this->clientId = $clientId;
    }

    /**
     * @param string $clientSecret
     */
    public function setClientSecret(string $clientSecret): void
    {
        $this->clientSecret = $clientSecret;
    }

    /**
     * @param string $sourceIdentifier
     */
    public function setSourceIdentifier(string $sourceIdentifier): void
    {
        $this->sourceIdentifier = $sourceIdentifier;
    }

    /**
     * @param string $devToken
     */
    public function setDevToken(string $devToken): void
    {
        $this->devToken = $devToken;
    }
}
