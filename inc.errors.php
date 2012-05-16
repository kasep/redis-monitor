<?php
namespace qad;
use LogicException, RuntimeException, Exception, ErrorException, Closure;

class ApplicationException extends LogicException
{
	const action_not_found = 1;
	const action_not_return_array = 2;
}

class QueryException extends RuntimeException
{
	const invalid = 3;
	const bad_parameter = 4;
}

class ParameterException extends QueryException
{
	function __construct( $p )
	{
		parent::__construct(sprintf('Invalid parameter: <%s>.',$p),parent::bad_parameter);
	}
}

class BadMemberException extends LogicException {}

class HttpException extends Exception
{
	protected $explain = '';
	function getExplain() { return $this->explain; }
	function __construct($code, $msg, $explain, Exception $prev = null)
	{
		$this->explain = (string)$explain;
		parent::__construct((string)$msg,(integer)$code,$prev);
	}
}

class HttpCreated extends HttpException { function __construct($msg='Created',Exception $prev=null) { parent::__construct(201,$msg,'The request has been fulfilled and resulted in a new resource being created.'); } }
class HttpNoContent extends HttpException { function __construct($msg='No Content',Exception $prev=null) { parent::__construct(204,$msg,'The server successfully processed the request, but is not returning any content.'); } }
class HttpBadRequest extends HttpException { function __construct($msg='Bad Request',Exception $prev=null) { parent::__construct(400,$msg,'The request could not be understood by the server due to malformed syntax. The client SHOULD NOT repeat the request without modifications.',$prev); } }
class HttpUnauthorized extends HttpException { function __construct($msg='Unauthorized',Exception $prev=null) { parent::__construct(401,$msg,'The request requires user authentication. The client MUST obtain an authorized session by authentication.',$prev); } }
class HttpNotFound extends HttpException { function __construct($msg='Not Found',Exception $prev=null) { parent::__construct(404,$msg,'The server has not found anything matching the Request-URI. The client SHOULD try to modify the request.',$prev); } }
class HttpPreconditionFailed extends HttpException { function __construct($msg='Precondition Failed',Exception $prev=null) { parent::__construct(412,$msg,'The server does not meet one of the preconditions that the requester put on the request.',$prev); } }
class HttpUnauthorizedMediaType extends HttpException { function __construct($msg='Unsupported Media Type',Exception $prev=null) { parent::__construct(415,$msg,'The request entity has a media type which the server or resource does not support.'); } }
class RequestedRangeNotSatisfiable extends HttpException { function __construct($msg='Requested Range Not Satisfiable',Exception $prev=null) { parent::__construct(416,$msg,'The client has asked for a portion of the file, but the server cannot supply that portion.'); } }
class HttpInternalServerError extends HttpException { function __construct($msg='Internal Server Error',Exception $prev=null) { parent::__construct(500,$msg,'The server encountered an unexpected condition which prevented it from fulfilling the request.',$prev); } }
class HttpNotImplemented extends HttpException { function __construct($msg='Not Implemented',Exception $prev=null) { parent::__construct(501,$msg,'The server does not support the functionality required to fulfill the request.',$prev); } }
class HttpNetworkAuthenticationRequired extends HttpException { function __construct($msg='Network Authentication Required',Exception $prev=null) { parent::__construct(511,$msg,'The client needs to authenticate to gain network access.'); } }

function error_handler($code, $msg, $file, $line)
{
	if( ! error_reporting() ) return;
	throw new ErrorException($msg, 0, $code, $file, $line);
}

function exception_handler( Exception $e, $status = null )
{
	switch( true )
	{
	case PHP_SAPI != 'cli':
		header('Content-Type: text/html; charset=utf-8');
		defined('server_signature') or define('server_signature','PHP');
		defined('server_version') or defined('server_version',PHP_VERSION);
		if( ! $status instanceof Closure ) $status = function(Exception $e) { return $e->getMessage(); };
		$signature = sprintf('%s %s at %s port %s',server_signature,server_version,getenv('SERVER_HOST'),getenv('SERVER_PORT'));
		$c = function($e) { return get_class($e); };
		$h = function($s) { return htmlentities($s); };
		if( $e instanceof HttpException )
		{
			header("HTTP/1.0 {$e->getCode()} {$status($e)}");
			echo <<<HTML
<!DOCTYPE HTML>
<html><head><title>{$e->getCode()} {$h($e->getMessage())}</title></head><body>
<h1 style="font-weight:normal"><strong>{$e->getCode()}: </strong>{$h($e->getMessage())}</h1>
<p>{$e->getExplain()}</p>
<dl>

HTML;
			$p = $e;
			while( $p = $p->getPrevious() )
				echo <<<HTML
<dt style="float:left"><strong>{$p->getCode()}: </strong></dt><dd>{$h($p->getMessage())}</dd>

HTML;
			echo <<<HTML
</dl>
<hr/><address>{$signature}</address>
</body></html>
HTML;
		}
		else
		{
			header("HTTP/1.0 500 Internal Server Error");
			echo <<<HTML
<!DOCTYPE HTML>
<html><head><title>500 Internal Server Error</title></head><body>
<h1>500</h1>
<p>Internal Server Error</p>
<hr/><address>{$signature}</address>
</body></html>
HTML;
		}
		break;
	}
	return true;
}
