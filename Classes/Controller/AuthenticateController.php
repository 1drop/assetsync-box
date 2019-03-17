<?php
namespace Onedrop\AssetSync\Box\Controller;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Uri;
use Neos\Flow\Mvc\Controller\ActionController;
use Ramsey\Uuid\Uuid;

/**
 * @Flow\Scope("singleton")
 */
class AuthenticateController extends ActionController
{
    /**
     * @var StringFrontend
     */
    protected $tokenCache;
    /**
     * @var array
     * @Flow\InjectConfiguration(package="DL.AssetSync", path="sourceConfiguration")
     */
    protected $assetSources;

    /**
     * @param  string                                                      $assetSourceIdentifier
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     * @throws \Neos\Flow\Mvc\Exception\UnsupportedRequestTypeException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    public function requestTokenAction(string $assetSourceIdentifier)
    {
        $assetSourceOptions = $this->assetSources[$assetSourceIdentifier]['sourceOptions'];
        $secureTempToken = Uuid::uuid4()->toString();
        $this->tokenCache->set($assetSourceIdentifier . '_tempToken', $secureTempToken);
        $redirectUri = $this->uriBuilder
            ->reset()
            ->setCreateAbsoluteUri(true)
            ->uriFor('receiveToken', [
                'assetSourceIdentifier' => $assetSourceIdentifier,
            ]);
        $params = [
            'response_type' => 'code',
            'client_id'     => $assetSourceOptions['clientId'],
            'redirect_uri'  => $redirectUri,
            'state'         => $secureTempToken,
        ];
        $this->redirectToUri(new Uri('https://account.box.com/api/oauth2/authorize?' . http_build_query($params)));
    }

    /**
     * @throws \Neos\Flow\Mvc\Exception\NoSuchArgumentException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     */
    public function initializeReceiveTokenAction()
    {
        if ($this->request->getHttpRequest()->hasArgument('code')) {
            $this->arguments->getArgument('code')->setValue($this->request->getHttpRequest()->getArgument('code'));
        }
    }

    /**
     * @param  string                                                   $assetSourceIdentifier
     * @param  string                                                   $code
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     * @throws \Neos\Flow\Mvc\Exception\UnsupportedRequestTypeException
     */
    public function receiveTokenAction(string $assetSourceIdentifier, string $code)
    {
        // TODO: validate temp security token
        $this->tokenCache->set($assetSourceIdentifier . '__auth-token', $code);
    }
}
