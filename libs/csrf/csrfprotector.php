<?php
include __DIR__ ."/csrfpCookieConfig.php";            //// cookie config class
include __DIR__ ."/csrfpDefaultLogger.php";           //// Logger class
include __DIR__ ."/csrfpAction.php";                  //// Actions enumerator

if (!defined('__CSRF_PROTECTOR__')) {
  define('__CSRF_PROTECTOR__', true);     // to avoid multiple declaration errors

  // name of HTTP POST variable for authentication
  define("CSRFP_TOKEN","csrfp_token");

  // We insert token name and list of url patterns for which
  // GET requests are validated against CSRF as hidden input fields
  // these are the names of the input fields
  define("CSRFP_FIELD_TOKEN_NAME", "csrfp_hidden_data_token");
  define("CSRFP_FIELD_URLS", "csrfp_hidden_data_urls");

  // Include the csrfpCookieConfig class

  /**
   * child exception classes
   */
  class configFileNotFoundException extends \exception {};
  class jsFileNotFoundException extends \exception {};
  class baseJSFileNotFoundExceptio extends \exception {};
  class incompleteConfigurationException extends \exception {};
  class alreadyInitializedException extends \exception {};

  class csrfProtector
  {
    /*
     * Variable: $isSameOrigin
     * flag for cross origin/same origin request
     * @var bool
     */
    private static $isSameOrigin = true;

    /*
     * Variable: $isValidHTML
     * flag to check if output file is a valid HTML or not
     * @var bool
     */
    private static $isValidHTML = false;

    /**
     * Variable: $cookieConfig
     * Array of parameters for the setcookie method
     * @var array<any>
     */
    private static $cookieConfig = null;

    /**
     * Variable: $logger
     * Logger class object
     * @var LoggerInterface
     */
    private static $logger = null;

    /**
     * Variable: $tokenHeaderKey
     * Key value in header array, which contain the token
     * @var string
     */
    private static $tokenHeaderKey = null;

    /*
     * Variable: $requestType
     * Variable to store whether request type is post or get
     * @var string
     */
    protected static $requestType = "GET";

    /**
     * @var array - internal urls to skip validation for
     */
    protected static $skipVerificationFor = [];

    /*
     * Variable: $config
     * config file for CSRFProtector
     * @var int Array, length = 6
     * Property: #1: failedAuthAction (int) => action to be taken in case autherisation fails
     * Property: #2: logDirectory (string) => directory in which log will be saved
     * Property: #3: customErrorMessage (string) => custom error message to be sent in case
     *                        of failed authentication
     * Property: #4: jsFile (string) => location of the CSRFProtector js file
     * Property: #5: tokenLength (int) => default length of hash
     */
    public static $config = array();

    /*
     * Variable: $requiredConfigurations
     * Contains list of those parameters that are required to be there
     *     in config file for csrfp to work
     */
    public static $requiredConfigurations  = array('logDirectory', 'failedAuthAction', 'tokenLength');

    /*
     * If active is set to false it won't log any errors and it won't render any code at self::renderHeaderTokens
     * This will be false when no verification is required based on configuration parameter self::$config['skipVerificationFor'].
     */
    public static $active = true;

    /*
     *    Function: init
      *
     *    function to initialise the csrfProtector work flow
     *
     *    Parameters:
     *    $length - length of CSRF_AUTH_TOKEN to be generated
     *    $action - int array, for different actions to be taken in case of failed validation
     *    $logger - custom logger class object
     *
     *    Returns:
     *        void
     *
     *    Throws:
     *        configFileNotFoundException - when configuration file is not found
     *         incompleteConfigurationException - when all required fields in config
     *                                            file are not available
     *
     */
    public static function init($configFile = null, $logger = null)
    {
      /*
       * Check if init has already been called.
       */
      if (count(self::$config) > 0) {
        throw new alreadyInitializedException("OWASP CSRFProtector: library was already initialized.");
      }

      /*
       * if mod_csrfp already enabled, no verification, no filtering
       * Already done by mod_csrfp
       */
      if (getenv('mod_csrfp_enabled'))
        return;

      // start session in case its not, and unit test is not going on
      if (session_id() == '' && !defined('__CSRFP_UNIT_TEST__'))
        session_start();

      /*
       * load configuration file and properties
       * Check locally for a config.php then check for
       * a config/csrf_config.php file in the root folder
       * for composer installations
       */
      $standard_config_location = __DIR__ ."/../config.php";
      $composer_config_location = __DIR__ ."/../../../../../config/csrf_config.php";

      if($configFile and file_exists($configFile)) {
        self::$config = include($configFile);
      } elseif (file_exists($standard_config_location)) {
        self::$config = include($standard_config_location);
      } elseif(file_exists($composer_config_location)) {
        self::$config = include($composer_config_location);
      } else {
        throw new configFileNotFoundException("OWASP CSRFProtector: configuration file not found for CSRFProtector!");
      }

      if (!empty(self::$config['skipVerificationFor']) and is_array(self::$config['skipVerificationFor'])) {
        self::$skipVerificationFor = self::$config['skipVerificationFor'];
      }

      if(isset(self::$config['active'])) {
        self::$active = self::$config['active'];
      }

      if (self::$config['CSRFP_TOKEN'] == '')
        self::$config['CSRFP_TOKEN'] = CSRFP_TOKEN;

      self::$tokenHeaderKey = 'HTTP_' .strtoupper(self::$config['CSRFP_TOKEN']);
      self::$tokenHeaderKey = str_replace('-', '_', self::$tokenHeaderKey);

      // load parameters for setcookie method
      if (!isset(self::$config['cookieConfig']))
        self::$config['cookieConfig'] = array();
      self::$cookieConfig = new csrfpCookieConfig(self::$config['cookieConfig']);

      // Validate the config if everything is filled out
      $missingConfiguration = [];
      foreach (self::$requiredConfigurations as $value) {
        if (!isset(self::$config[$value]) || self::$config[$value] === '') {
          $missingConfiguration[] = $value;
        }
      }

      if ($missingConfiguration) {
        throw new incompleteConfigurationException(
          'OWASP CSRFProtector: Incomplete configuration file: missing ' .
          implode(', ', $missingConfiguration) . ' value(s)');
      }

      // iniialize the logger class
      if ($logger !== null) {
        self::$logger = $logger;
      } else {
        self::$logger = new csrfpDefaultLogger(self::$config['logDirectory']);
      }

    }

    /*
     * Function: renderHeaderTokens
     * function to render the block of code that we must add to the header of each page
     *
     * Parameters:
     * void
     *
     * Returns:
     * string
     */
    public static function renderHeaderTokens()
    {
      if (!self::$active) {
        return null;
      }

      $headerTokens = '<meta name="' . CSRFP_FIELD_TOKEN_NAME . '" content="' . self::$config['CSRFP_TOKEN'] . '">' . PHP_EOL;
      $headerTokens .= '<meta name="' . CSRFP_FIELD_URLS . '" content="' . urlencode(json_encode(self::$config['skipTokenForUrl'] ?? [])) . '">' . PHP_EOL;
      if (!empty(self::$config['cookieConfig']['httpOnly'])) {
          $headerTokens .= '<meta name="' . self::$config['CSRFP_TOKEN'] . '" content="' . ($_COOKIE[self::$config['CSRFP_TOKEN']] ?? '') . '">' . PHP_EOL;
      }
      return $headerTokens;
    }

    /*
     * Function: authorizePost
     * function to authorise incoming post requests
     *
     * Parameters:
     * void
     *
     * Returns:
     * void
     *
     * Throws:
     * logDirectoryNotFoundException - if log directory is not found
     */
    public static function authorizePost()
    {
      if(!self::$active) {
        return null;
      }

      //#todo this method is valid for same origin request only,
      //enable it for cross origin also sometime
      //for cross origin the functionality is different

      //Added by @alexlazar: won't work for CORS requests because we can't verify the token value when sent as a custom
      //header, we can only verify its existence (token value gets stale in front-end causing mismatch)
      //see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html#employing-custom-request-headers-for-ajaxapi
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (self::skipVerificationFor()) {
          return null;
        }

        //set request type to POST
        self::$requestType = "POST";

        // look for token in header and payload
        $tokenAndSource = self::getTokenFromRequest();
        $token = !empty($tokenAndSource['token']) ? $tokenAndSource['token'] : null;
        $source = !empty($tokenAndSource['source']) ? $tokenAndSource['source'] : null;

        // if the token is part of the header, its existence is enough
        if ($source == 'apache_request_headers' && $token) {
            self::unsetTokenFromRequest();
            return null;
        }

        //currently for same origin only
        if (!($token && isset($_SESSION[self::$config['CSRFP_TOKEN']])
          && (self::isValidToken($token)))) {
          //action in case of failed validation
          self::failedValidationAction();
        }

        self::unsetTokenFromRequest();
      }
    }

    /*
     * Function: getTokenFromRequest
     * function to get token and token source in case of POST request
     *
     * Parameters:
     * void
     *
     * Returns:
     * array|bool - an array of [token, source] or false
     */
    private static function getTokenFromRequest() {
      // look for token in header
      if (function_exists('apache_request_headers')) {
          $apacheRequestHeaders = apache_request_headers();
          if (isset($apacheRequestHeaders[self::$config['CSRFP_TOKEN']])) {
              return ['token' => $apacheRequestHeaders[self::$config['CSRFP_TOKEN']], 'source' => 'apache_request_headers'];
          }
      }

      // look for token in $_POST
      if (isset($_POST[self::$config['CSRFP_TOKEN']])) {
        return ['token' => $_POST[self::$config['CSRFP_TOKEN']], 'source' => '$_POST'];
      }

      if (self::$tokenHeaderKey === null)
          return false;

      if (isset($_SERVER[self::$tokenHeaderKey])) {
        return ['token' => $_SERVER[self::$tokenHeaderKey], 'source' => '$_SERVER'];
      }

      return false;
    }

    /*
     * Function: unsetTokenFromRequest
     * check if the token exists at the $_POST and if so, unset it.
     *
     * Parameters:
     * void
     *
     * Returns:
     * void
     */
    private static function unsetTokenFromRequest() {
      // look for in $_POST, then header
      if (isset($_POST[self::$config['CSRFP_TOKEN']])) {
        unset($_POST[self::$config['CSRFP_TOKEN']]);
      }
    }

    /*
     * Function: isValidToken
     * function to check the validity of token in session array
     * Function also clears all tokens older than latest one
     *
     * Parameters:
     * $token - the token sent with GET or POST payload
     *
     * Returns:
     * bool - true if its valid else false
     */
    private static function isValidToken($token) {
      if (!isset($_SESSION[self::$config['CSRFP_TOKEN']])) return false;
      if (!is_array($_SESSION[self::$config['CSRFP_TOKEN']])) return false;
      foreach ($_SESSION[self::$config['CSRFP_TOKEN']] as $key => $value) {
        if ($value == $token) {

          // Clear all older tokens assuming they have been consumed
          foreach ($_SESSION[self::$config['CSRFP_TOKEN']] as $_key => $_value) {
            if ($_value == $token) break;
            array_shift($_SESSION[self::$config['CSRFP_TOKEN']]);
          }
          return true;
        }
      }

      return false;
    }

    /**
     * Function: failedValidationAction
     * function to be called in case of failed validation
     * performs logging and take appropriate action
     *
     * @throws \Exception $e
     *
     * Parameters:
     * void
     *
     * Returns:
     * void
     */
    private static function failedValidationAction()
    {
      if(!self::$active) {
        return null;
      }
      //call the logging function
      static::logCSRFattack();

      //#todo: ask mentors if $failedAuthAction is better as an int or string
      //default case is case 0
      switch (self::$config['failedAuthAction'][self::$requestType]) {
        case csrfpAction::TriggerErrorAction:
          trigger_error("CSRF Token Validation Failed");
          break;
        case csrfpAction::LogOnlyAction:
          //Do nothing, already logged above.
          break;
        case csrfpAction::ThrowExceptionAction:
          throw new \Exception("CSRF Token Validation Failed");
          break;
        case csrfpAction::ForbiddenResponseAction:
          //send 403 header
          header('HTTP/1.0 403 Forbidden');
          exit("<h2>403 Access Forbidden by CSRFProtector!</h2>");
          break;
        case csrfpAction::ClearParametersAction:
          //unset the query parameters and forward
          if (self::$requestType === 'GET') {
            $_GET = array();
          } else {
            $_POST = array();
          }
          break;
        case csrfpAction::RedirectAction:
          //redirect to custom error page
          $location  = self::$config['errorRedirectionPage'];
          header("location: $location");
          exit(self::$config['customErrorMessage']);
          break;
        case csrfpAction::CustomErrorMessageAction:
          //send custom error message
          exit(self::$config['customErrorMessage']);
          break;
        case csrfpAction::InternalServerErrorResponseAction:
          //send 500 header -- internal server error
          header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
          exit("<h2>500 Internal Server Error!</h2>");
          break;
        default:
          //unset the query parameters and forward
          if (self::$requestType === 'GET') {
            $_GET = array();
          } else {
            $_POST = array();
          }
          break;
      }
    }

    /*
     * Function: hasToken
     * Function to check if there already is a token stored at the section
     *
     * Parameters:
     * void
     *
     * Returns:
     * boolean
     */
    public static function hasToken()
    {
      if (
        !isset($_SESSION[self::$config['CSRFP_TOKEN']]) ||
        !is_array($_SESSION[self::$config['CSRFP_TOKEN']]) ||
        count($_SESSION[self::$config['CSRFP_TOKEN']]) === 0 ||
        !isset($_COOKIE[self::$config['CSRFP_TOKEN']]) ||
        empty($_COOKIE[self::$config['CSRFP_TOKEN']]) ||
        !in_array($_COOKIE[self::$config['CSRFP_TOKEN']], $_SESSION[self::$config['CSRFP_TOKEN']]) //Cookie and session went out of sync, that's the same as not having a token
      ) {
        return false;
      }
      return true;
    }


    /*
     * Function: refreshToken
     * Function to set auth cookie
     *
     * Parameters:
     * void
     *
     * Returns:
     * void
     */
    public static function refreshToken()
    {
      $token = self::generateAuthToken();

      $_SESSION[self::$config['CSRFP_TOKEN']] = array();

      // set token to session for server side validation
      array_push($_SESSION[self::$config['CSRFP_TOKEN']], $token);

      // set token to cookie for client side processing
      if (self::$cookieConfig === null) {
        if (!isset(self::$config['cookieConfig']))
          self::$config['cookieConfig'] = array();
        self::$cookieConfig = new csrfpCookieConfig(self::$config['cookieConfig']);
      }

      setcookie(
        self::$config['CSRFP_TOKEN'],
        $token,
        (self::$cookieConfig->expire !== -1) ? time() + self::$cookieConfig->expire : 0,
        self::$cookieConfig->path,
        self::$cookieConfig->domain,
        (bool) self::$cookieConfig->secure,
        !empty(self::$cookieConfig->httpOnly)
      );

      /*
       * We force this update here because otherwise the $_COOKIE global var would only get updated on the next request,
       * so any subsequent call to \csrfProtector::hasToken() would fail because $_COOKIE[$key] would differ from $_SESSION[$key]
       * this is specially likely to happen on the login and logout flows where the tokens are regenerated even if there was already a previous token.
       */
      $_COOKIE[self::$config['CSRFP_TOKEN']] = $token;
    }

    /*
     * Function: generateAuthToken
     * function to generate random hash of length as given in parameter
     * max length = 128
     *
     * Parameters:
     * length to hash required, int
     *
     * Returns:
     * string, token
     */
    public static function generateAuthToken()
    {
      // todo - make this a member method / configurable
      $randLength = 64;

      //if config tokenLength value is 0 or some non int
      if (intval(self::$config['tokenLength']) == 0) {
        self::$config['tokenLength'] = 32;    //set as default
      }

      //#todo - if $length > 128 throw exception

      if (function_exists("random_bytes")) {
        $token = bin2hex(random_bytes($randLength));
      } elseif (function_exists("openssl_random_pseudo_bytes")) {
        $token = bin2hex(openssl_random_pseudo_bytes($randLength));
      } else {
        $token = '';
        for ($i = 0; $i < 128; ++$i) {
          $r = mt_rand (0, 35);
          if ($r < 26) {
            $c = chr(ord('a') + $r);
          } else {
            $c = chr(ord('0') + $r - 26);
          }
          $token .= $c;
        }
      }
      return substr($token, 0, self::$config['tokenLength']);
    }

    /*
     * Function: logCSRFattack
     * Function to log CSRF Attack
     *
     * Parameters:
     * void
     *
     * Returns:
     * void
     *
     * Throws:
     * logFileWriteError - if unable to log an attack
     */
    protected static function logCSRFattack()
    {
      $tokenAndSource = self::getTokenFromRequest()['token'];

      //miniature version of the log
      $context = array();
      $context['HOST'] = $_SERVER['HTTP_HOST'];
      $context['REQUEST_URI'] = $_SERVER['REQUEST_URI'];
      $context['REQUEST_TYPE'] = self::$requestType;
      $context['COOKIE'] = $_COOKIE;
      $context['RECEIVED_TOKEN'] = isset($tokenAndSource['token']) ? $tokenAndSource['token'] : null;
      $context['RECEIVED_TOKEN_SOURCE'] = isset($tokenAndSource['source']) ? $tokenAndSource['source'] : null;
      $context['SESSION'] = (isset($_SESSION[self::$config['CSRFP_TOKEN']])) ? $_SESSION[self::$config['CSRFP_TOKEN']] : null;

      self::$logger->log("OWASP CSRF PROTECTOR VALIDATION FAILURE", $context);
    }

    /*
     * Function: getCurrentUrl
     * Function to return current url of executing page
     *
     * Parameters:
     * void
     *
     * Returns:
     * string - current url
     */
    private static function getCurrentUrl()
    {
      $url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
      $url = parse_url($url);
      $path = explode("/", trim($url['path'], "/"));
      $lastParam = array_pop($path);
      if (!is_numeric($lastParam)) {
        $path[] = $lastParam;
      }
      $currentUrl = implode("/", $path);
      return $currentUrl;
    }

    private static function skipVerificationFor()
    {
      $currentUrl = self::getCurrentUrl();

      if (in_array($currentUrl, self::$skipVerificationFor)) {
        return true;
      }

      return false;
    }
  }
}
