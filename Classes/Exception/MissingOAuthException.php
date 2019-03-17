<?php
namespace Onedrop\AssetSync\Box\Exception;

use Neos\Flow\Exception as FlowException;
use Neos\Media\Domain\Model\AssetSource\AssetSourceConnectionExceptionInterface;

class MissingOAuthException extends FlowException implements AssetSourceConnectionExceptionInterface
{
}
