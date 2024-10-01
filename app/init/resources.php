<?php


use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Authentication;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\Migration;
use Appwrite\Event\Usage;
use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Schema;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Queue\Connections;
use Utopia\Cache\Adapter\Redis as CacheRedis;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DI\Dependency;
use Utopia\DSN\DSN;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Router;
use Utopia\Http\Validator\Hostname;
use Utopia\Locale\Locale;
use Utopia\Logger\Log;
use Utopia\Queue;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

global $container;

function getDevice($root): Device
{
    $connection = System::getEnv('_APP_CONNECTIONS_STORAGE', '');

    if (!empty($connection)) {
        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $bucket = '';
        $region = '';

        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
            $accessKey = $dsn->getUser() ?? '';
            $accessSecret = $dsn->getPassword() ?? '';
            $bucket = $dsn->getPath() ?? '';
            $region = $dsn->getParam('region');
        } catch (\Throwable $e) {
            Console::warning($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
        }

        switch ($device) {
            case Storage::DEVICE_S3:
                return new S3($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case STORAGE::DEVICE_DO_SPACES:
                return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    } else {
        switch (strtolower(System::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? '')) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = System::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
                $s3SecretKey = System::getEnv('_APP_STORAGE_S3_SECRET', '');
                $s3Region = System::getEnv('_APP_STORAGE_S3_REGION', '');
                $s3Bucket = System::getEnv('_APP_STORAGE_S3_BUCKET', '');
                $s3Acl = 'private';
                return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = System::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = System::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = System::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = System::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = System::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
                $backblazeSecretKey = System::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
                $backblazeRegion = System::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
                $backblazeBucket = System::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = System::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
                $linodeSecretKey = System::getEnv('_APP_STORAGE_LINODE_SECRET', '');
                $linodeRegion = System::getEnv('_APP_STORAGE_LINODE_REGION', '');
                $linodeBucket = System::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = System::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
                $wasabiSecretKey = System::getEnv('_APP_STORAGE_WASABI_SECRET', '');
                $wasabiRegion = System::getEnv('_APP_STORAGE_WASABI_REGION', '');
                $wasabiBucket = System::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}

$log = new Dependency();
$mode = new Dependency();
$user = new Dependency();
$team = new Dependency();
$plan = new Dependency();
$pools = new Dependency();
$geodb = new Dependency();
$cache = new Dependency();
$pools = new Dependency();
$queue = new Dependency();
$hooks = new Dependency();
$logger = new Dependency();
$locale = new Dependency();
$schema = new Dependency();
$github = new Dependency();
$session = new Dependency();
$console = new Dependency();
$project = new Dependency();
$clients = new Dependency();
$servers = new Dependency();
$register = new Dependency();
$connections = new Dependency();
$localeCodes = new Dependency();
$getConsoleDB = new Dependency();
$getProjectDB = new Dependency();
$dbForProject = new Dependency();
$dbForConsole = new Dependency();
$queueForUsage = new Dependency();
$queueForMails = new Dependency();
$authorization = new Dependency();
$authentication = new Dependency();
$queueForBuilds = new Dependency();
$deviceForLocal = new Dependency();
$deviceForFiles = new Dependency();
$queueForEvents = new Dependency();
$queueForAudits = new Dependency();
$promiseAdapter = new Dependency();
$schemaVariable = new Dependency();
$deviceForBuilds = new Dependency();
$queueForDeletes = new Dependency();
$requestTimestamp = new Dependency();
$queueForDatabase = new Dependency();
$queueForMessaging = new Dependency();
$queueForFunctions = new Dependency();
$queueForMigrations = new Dependency();
$deviceForFunctions = new Dependency();
$passwordsDictionary = new Dependency();
$queueForCertificates = new Dependency();


$team
    ->setName('team')
    ->inject('project')
    ->inject('request')
    ->inject('dbForConsole')
    ->inject('authorization')
    ->setCallback(function (Document $project, Request $request, Database $dbForConsole, Authorization $authorization) {
        $teamInternalId = '';
        if ($project->getId() !== 'console') {
            $teamInternalId = $project->getAttribute('teamInternalId', '');
        } else {
            $method = (Http::REQUEST_METHOD_HEAD == $request->getMethod()) ? Http::REQUEST_METHOD_GET : $request->getMethod();
            $route = Router::match($method, $request->getURI());

            if ($route !== null) {
                $path = $route->getPath();
                if (str_starts_with($path, '/v1/projects/:projectId')) {
                    $uri = $request->getURI();
                    $pid = explode('/', $uri)[3];
                    $p = $authorization->skip(fn () => $dbForConsole->getDocument('projects', $pid));
                    $teamInternalId = $p->getAttribute('teamInternalId', '');
                } elseif ($path === '/v1/projects') {
                    $teamId = $request->getParam('teamId', '');
                    $team = $authorization->skip(fn () => $dbForConsole->getDocument('teams', $teamId));
                    return $team;
                }
            }
        }

        $team = $authorization->skip(function () use ($dbForConsole, $teamInternalId) {
            return $dbForConsole->findOne('teams', [
                Query::equal('$internalId', [$teamInternalId]),
            ]);
        });

        if (!$team) {
            $team = new Document([]);
        }
        return $team;
    });


$plan
    ->setName('plan')
    ->setCallback(fn () => []);

$mode
    ->setName('mode')
    ->inject('request')
    ->setCallback(function (Request $request) {
        /**
         * Defines the mode for the request:
         * - 'default' => Requests for Client and Server Side
         * - 'admin' => Request from the Console on non-console projects
         */
        return $request->getParam('mode', $request->getHeader('x-appwrite-mode', APP_MODE_DEFAULT));
    });

$user
    ->setName('user')
    ->inject('mode')
    ->inject('project')
    ->inject('console')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('authorization')
    ->inject('authentication')
    ->setCallback(function (string $mode, Document $project, Document $console, Request $request, Response $response, Database $dbForProject, Database $dbForConsole, Authorization $authorization, Authentication $authentication) {
        $authorization->setDefaultStatus(true);
        $authentication->setCookieName('a_session_' . $project->getId());

        if (APP_MODE_ADMIN === $mode) {
            $authentication->setCookieName('a_session_' . $console->getId());
        }

        $session = Auth::decodeSession(
            $request->getCookie(
                $authentication->getCookieName(), // Get sessions
                $request->getCookie($authentication->getCookieName() . '_legacy', '')
            )
        );

        // Get session from header for SSR clients
        if (empty($session['id']) && empty($session['secret'])) {
            $sessionHeader = $request->getHeader('x-appwrite-session', '');

            if (!empty($sessionHeader)) {
                $session = Auth::decodeSession($sessionHeader);
            }
        }

        // Get fallback session from old clients (no SameSite support) or clients who block 3rd-party cookies
        if ($response) {
            $response->addHeader('X-Debug-Fallback', 'false');
        }

        if (empty($session['id']) && empty($session['secret'])) {
            if ($response) {
                $response->addHeader('X-Debug-Fallback', 'true');
            }
            $fallback = $request->getHeader('x-fallback-cookies', '');
            $fallback = \json_decode($fallback, true);
            $session = Auth::decodeSession(((isset($fallback[$authentication->getCookieName()])) ? $fallback[$authentication->getCookieName()] : ''));
        }

        $authentication->setUnique($session['id'] ?? '');
        $authentication->setSecret($session['secret'] ?? '');

        if (APP_MODE_ADMIN !== $mode) {
            if ($project->isEmpty()) {
                $user = new Document([]);
            } else {
                if ($project->getId() === 'console') {
                    $user = $dbForConsole->getDocument('users', $authentication->getUnique());
                } else {
                    $user = $dbForProject->getDocument('users', $authentication->getUnique());
                }
            }
        } else {
            $user = $dbForConsole->getDocument('users', $authentication->getUnique());
        }

        if (
            $user->isEmpty() // Check a document has been found in the DB
            || !Auth::sessionVerify($user->getAttribute('sessions', []), $authentication->getSecret())
        ) { // Validate user has valid login token
            $user = new Document([]);
        }

        //        if (APP_MODE_ADMIN === $mode) {
        //            if ($user->find('teamId', $project->getAttribute('teamId'), 'memberships')) {
        //                $authorization->setDefaultStatus(false);  // Cancel security segmentation for admin users.
        //            } else {
        //                $user = new Document([]);
        //            }
        //        }

        $authJWT = $request->getHeader('x-appwrite-jwt', '');

        if (!empty($authJWT) && !$project->isEmpty()) { // JWT authentication
            $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);
            try {
                $payload = $jwt->decode($authJWT);
            } catch (JWTException $error) {
                $request->removeHeader('x-appwrite-jwt');
                throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
            }

            $jwtUserId = $payload['userId'] ?? '';
            if (!empty($jwtUserId)) {
                $user = $dbForProject->getDocument('users', $jwtUserId);
            }

            $jwtSessionId = $payload['sessionId'] ?? '';
            if (!empty($jwtSessionId)) {
                if (empty($user->find('$id', $jwtSessionId, 'sessions'))) { // Match JWT to active token
                    $user = new Document([]);
                }
            }
        }

        // Adds logs to database queries
        $dbForProject->setMetadata('user', $user->getId());
        $dbForConsole->setMetadata('user', $user->getId());

        return $user;
    });


$session
    ->setName('session')
    ->inject('user')
    ->inject('project')
    ->inject('authorization')
    ->inject('authentication')
    ->setCallback(function (Document $user, Document $project, Authorization $authorization, Authentication $authentication) {
        if ($user->isEmpty()) {
            return;
        }

        $sessions = $user->getAttribute('sessions', []);
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $sessionId = Auth::sessionVerify($user->getAttribute('sessions'), $authentication->getSecret(), $authDuration);

        if (!$sessionId) {
            return;
        }

        foreach ($sessions as $session) {
            if ($sessionId === $session->getId()) {
                return $session;
            }
        }

        return;
    });

$console
    ->setName('console')
    ->setCallback(function () {
        return new Document([
            '$id' => ID::custom('console'),
            '$internalId' => ID::custom('console'),
            'name' => 'Appwrite',
            '$collection' => ID::custom('projects'),
            'description' => 'Appwrite core engine',
            'logo' => '',
            'teamId' => null,
            'webhooks' => [],
            'keys' => [],
            'platforms' => [
                [
                    '$collection' => ID::custom('platforms'),
                    'name' => 'Localhost',
                    'type' => Origin::CLIENT_TYPE_WEB,
                    'hostname' => 'localhost',
                ], // Current host is added on app init
            ],
            'legalName' => '',
            'legalCountry' => '',
            'legalState' => '',
            'legalCity' => '',
            'legalAddress' => '',
            'legalTaxId' => '',
            'auths' => [
                'mockNumbers' => [],
                'invites' => System::getEnv('_APP_CONSOLE_INVITES', 'enabled') === 'enabled',
                'limit' => (System::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled') === 'enabled') ? 1 : 0, // limit signup to 1 user
                'duration' => Auth::TOKEN_EXPIRATION_LOGIN_LONG, // 1 Year in seconds
                'sessionAlerts' => System::getEnv('_APP_CONSOLE_SESSION_ALERTS', 'disabled') === 'enabled'
            ],
            'authWhitelistEmails' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null)) : [],
            'authWhitelistIPs' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null)) : [],
            'oAuthProviders' => [
                'githubEnabled' => true,
                'githubSecret' => System::getEnv('_APP_CONSOLE_GITHUB_SECRET', ''),
                'githubAppid' => System::getEnv('_APP_CONSOLE_GITHUB_APP_ID', '')
            ],
        ]);
    });


$project
    ->setName('project')
    ->inject('dbForConsole')
    ->inject('request')
    ->inject('console')
    ->inject('authorization')
    ->setCallback(function (Database $dbForConsole, Request $request, Document $console, Authorization $authorization) {
        $projectId = $request->getParam('project', $request->getHeader('x-appwrite-project', ''));

        if (empty($projectId) || $projectId === 'console') {
            return $console;
        }

        $project = $authorization->skip(fn () => $dbForConsole->getDocument('projects', $projectId));

        return $project;
    });

$pools
    ->setName('pools')
    ->inject('registry')
    ->setCallback(function (Registry $registry) {
        return $registry->get('pools');
    });

$dbForProject
    ->setName('dbForProject')
    ->inject('cache')
    ->inject('pools')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('authorization')
    ->inject('connections')
    ->setCallback(function (Cache $cache, array $pools, Document $project, Database $dbForConsole, Authorization $authorization, Connections $connections) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForConsole;
        }

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        $pool = $pools['pools-database-' . $dsn->getHost()]['pool'];
        $connectionDsn = $pools['pools-database-' . $dsn->getHost()]['dsn'];

        $connection = $pool->get();
        $connections->add($connection, $pool);
        $adapter = match ($connectionDsn->getScheme()) {
            'mariadb' => new MariaDB($connection),
            'mysql' => new MySQL($connection),
            default => null
        };

        $adapter->setDatabase($connectionDsn->getPath());

        $database = new Database($adapter, $cache);

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        if ($dsn->getHost() === DATABASE_SHARED_TABLES) {
            $database
                ->setSharedTables(true)
                ->setTenant($project->getInternalId())
                ->setNamespace($dsn->getParam('namespace'));
        } else {
            $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getInternalId());
        }

        $database->setAuthorization($authorization);
        return $database;
    });

$dbForConsole
    ->setName('dbForConsole')
    ->inject('getConsoleDB')
    ->inject('connections')
    ->setCallback(function (callable $getConsoleDB, Connections $connections): Database {
        [$connection,$pool, $database] = $getConsoleDB();
        $connections->add($connection, $pool);

        return $database;
    });

$cache
    ->setName('cache')
    ->inject('pools')
    ->inject('connections')
    ->setCallback(function (array $pools, Connections $connections) {
        $adapters = [];
        $databases = Config::getParam('pools-cache');

        foreach ($databases as $database) {
            $pool = $pools['pools-cache-' . $database]['pool'];
            $dsn = $pools['pools-cache-' . $database]['dsn'];

            $connection = $pool->get();
            $connections->add($connection, $pool);

            $adapters[] = new CacheRedis($connection);
        }

        return new Cache(new Sharding($adapters));
    });

$authorization
    ->setName('authorization')
    ->setCallback(function (): Authorization {
        return new Authorization();
    });

$authentication
    ->setName('authentication')
    ->setCallback(function (): Authentication {
        return new Authentication();
    });

$register
    ->setName('registry')
    ->setCallback(function () use (&$registry): Registry {
        return $registry;
    });

$pools
    ->setName('pools')
    ->inject('registry')
    ->setCallback(function (Registry $registry) {
        return $registry->get('pools');
    });

$logger
    ->setName('logger')
    ->inject('registry')
    ->setCallback(function (Registry $registry) {
        return $registry->get('logger');
    });

$log
    ->setName('log')
    ->setCallback(function () {
        return new Log();
    });

$connections
    ->setName('connections')
    ->setCallback(function () {
        return new Connections();
    });

$locale
    ->setName('locale')
    ->setCallback(fn () => new Locale(System::getEnv('_APP_LOCALE', 'en')));

$localeCodes
    ->setName('localeCodes')
    ->setCallback(fn () => array_map(fn ($locale) => $locale['code'], Config::getParam('locale-codes', [])));

$queue
    ->setName('queue')
    ->inject('pools')
    ->inject('connections')
    ->setCallback(function (array $pools, Connections $connections) {
        $pool = $pools['pools-queue-queue']['pool'];
        $connection = $pool->get();
        $connections->add($connection, $pool);

        return new Queue\Connection\Redis($connection);
    });

$queueForMessaging
    ->setName('queueForMessaging')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Messaging($queue);
    });

$queueForMails
    ->setName('queueForMails')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Mail($queue);
    });

$queueForBuilds
    ->setName('queueForBuilds')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Build($queue);
    });

$queueForDatabase
    ->setName('queueForDatabase')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new EventDatabase($queue);
    });

$queueForDeletes
    ->setName('queueForDeletes')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Delete($queue);
    });

$queueForEvents
    ->setName('queueForEvents')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Event($queue);
    });

$queueForAudits
    ->setName('queueForAudits')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Audit($queue);
    });

$queueForFunctions
    ->setName('queueForFunctions')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Func($queue);
    });

$queueForUsage
    ->setName('queueForUsage')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Usage($queue);
    });

$queueForCertificates
    ->setName('queueForCertificates')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Certificate($queue);
    });

$queueForMigrations
    ->setName('queueForMigrations')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Migration($queue);
    });

$deviceForLocal
    ->setName('deviceForLocal')
    ->setCallback(function () {
        return new Local();
    });

$deviceForFiles
    ->setName('deviceForFiles')
    ->inject('project')
    ->setCallback(function ($project) {
        return getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
    });

$deviceForFunctions
    ->setName('deviceForFunctions')
    ->inject('project')
    ->setCallback(function ($project) {
        return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId());
    });

$deviceForBuilds
    ->setName('deviceForBuilds')
    ->inject('project')
    ->setCallback(function ($project) {
        return getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId());
    });

$clients
    ->setName('clients')
    ->inject('request')
    ->inject('console')
    ->inject('project')
    ->setCallback(function (Request $request, Document $console, Document $project) {
        $console->setAttribute('platforms', [ // Always allow current host
            '$collection' => ID::custom('platforms'),
            'name' => 'Current Host',
            'type' => Origin::CLIENT_TYPE_WEB,
            'hostname' => $request->getHostname(),
        ], Document::SET_TYPE_APPEND);

        $hostnames = explode(',', System::getEnv('_APP_CONSOLE_HOSTNAMES', ''));
        $validator = new Hostname();
        foreach ($hostnames as $hostname) {
            $hostname = trim($hostname);
            if (!$validator->isValid($hostname)) {
                continue;
            }
            $console->setAttribute('platforms', [
                '$collection' => ID::custom('platforms'),
                'type' => Origin::CLIENT_TYPE_WEB,
                'name' => $hostname,
                'hostname' => $hostname,
            ], Document::SET_TYPE_APPEND);
        }

        /**
         * Get All verified client URLs for both console and current projects
         * + Filter for duplicated entries
         */
        $clientsConsole = \array_map(
            fn ($node) => $node['hostname'],
            \array_filter(
                $console->getAttribute('platforms', []),
                fn ($node) => (isset($node['type']) && ($node['type'] === Origin::CLIENT_TYPE_WEB) && isset($node['hostname']) && !empty($node['hostname']))
            )
        );

        $clients = \array_unique(
            \array_merge(
                $clientsConsole,
                \array_map(
                    fn ($node) => $node['hostname'],
                    \array_filter(
                        $project->getAttribute('platforms', []),
                        fn ($node) => (isset($node['type']) && ($node['type'] === Origin::CLIENT_TYPE_WEB || $node['type'] === Origin::CLIENT_TYPE_FLUTTER_WEB) && isset($node['hostname']) && !empty($node['hostname']))
                    )
                )
            )
        );

        return $clients;
    });

$servers
    ->setName('servers')
    ->setCallback(function () {
        $platforms = Config::getParam('platforms');
        $server = $platforms[APP_PLATFORM_SERVER];

        $languages = array_map(function ($language) {
            return strtolower($language['name']);
        }, $server['sdks']);

        return $languages;
    });

$geodb
    ->setName('geodb')
    ->inject('registry')
    ->setCallback(function (Registry $register) {
        return $register->get('geodb');
    });

$passwordsDictionary
    ->setName('passwordsDictionary')
    ->setCallback(function () {
        $content = file_get_contents(__DIR__ . '/../assets/security/10k-common-passwords');
        $content = explode("\n", $content);
        $content = array_flip($content);
        return $content;
    });

$hooks
    ->setName('hooks')
    ->inject('registry')
    ->setCallback(function (Registry $registry) {
        return $registry->get('hooks');
    });

$github
    ->setName('gitHub')
    ->inject('cache')
    ->setCallback(function (Cache $cache) {
        return new GitHub($cache);
    });

$requestTimestamp
    ->setName('requestTimestamp')
    ->inject('request')
    ->setCallback(function ($request) {
        $timestampHeader = $request->getHeader('x-appwrite-timestamp');
        $requestTimestamp = null;
        if (!empty($timestampHeader)) {
            try {
                $requestTimestamp = new \DateTime($timestampHeader);
            } catch (\Throwable $e) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid X-Appwrite-Timestamp header value');
            }
        }
        return $requestTimestamp;
    });
$getConsoleDB
    ->setName('getConsoleDB')
    ->inject('pools')
    ->inject('cache')
    ->inject('authorization')
    ->inject('connections')
    ->setCallback(function (array $pools, Cache $cache, Authorization $authorization) {
        return function () use ($pools, $cache, $authorization): array {
            $pool = $pools['pools-console-console']['pool'];
            $dsn = $pools['pools-console-console']['dsn'];
            $connection = $pool->get();

            $adapter = match ($dsn->getScheme()) {
                'mariadb' => new MariaDB($connection),
                'mysql' => new MySQL($connection),
                default => null
            };

            $adapter->setDatabase($dsn->getPath());

            $database = new Database($adapter, $cache);
            $database->setAuthorization($authorization);

            $database
                ->setNamespace('_console')
                ->setMetadata('host', \gethostname())
                ->setMetadata('project', 'console')
                ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS);

            return [$connection, $pool, $database];
        };
    });

$getProjectDB
    ->setName('getProjectDB')
    ->inject('pools')
    ->inject('dbForConsole')
    ->inject('cache')
    ->inject('authorization')
    ->inject('connections')
    ->setCallback(function (array $pools, Database $dbForConsole, Cache $cache, Authorization $authorization, Connections $connections) {
        $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

        return function (Document $project) use ($pools, $dbForConsole, $cache, &$databases, $authorization, $connections): Database {
            if ($project->isEmpty() || $project->getId() === 'console') {
                return $dbForConsole;
            }

            try {
                $dsn = new DSN($project->getAttribute('database'));
            } catch (\InvalidArgumentException) {
                // TODO: Temporary until all projects are using shared tables
                $dsn = new DSN('mysql://' . $project->getAttribute('database'));
            }

            if (isset($databases[$dsn->getHost()])) {
                $database = $databases[$dsn->getHost()];

                if ($dsn->getHost() === DATABASE_SHARED_TABLES) {
                    $database
                        ->setSharedTables(true)
                        ->setTenant($project->getInternalId())
                        ->setNamespace($dsn->getParam('namespace'));
                } else {
                    $database
                        ->setSharedTables(false)
                        ->setTenant(null)
                        ->setNamespace('_' . $project->getInternalId());
                }

                return $database;
            }

            $pool = $pools['pools-database-' . $dsn->getHost()]['pool'];
            $connectionDsn = $pools['pools-database-' . $dsn->getHost()]['dsn'];

            $connection = $pool->get();
            $connections->add($connection, $pool);
            $adapter = match ($connectionDsn->getScheme()) {
                'mariadb' => new MariaDB($connection),
                'mysql' => new MySQL($connection),
                default => null
            };
            $adapter->setDatabase($connectionDsn->getPath());

            $database = new Database($adapter, $cache);
            $database->setAuthorization($authorization);

            $databases[$dsn->getHost()] = $database;

            if ($dsn->getHost() === DATABASE_SHARED_TABLES) {
                $database
                    ->setSharedTables(true)
                    ->setTenant($project->getInternalId())
                    ->setNamespace($dsn->getParam('namespace'));
            } else {
                $database
                    ->setSharedTables(false)
                    ->setTenant(null)
                    ->setNamespace('_' . $project->getInternalId());
            }

            return $database;
        };
    });

$promiseAdapter
    ->setName('promiseAdapter')
    ->setCallback(function () use ($registry) {
        return $registry->get('promiseAdapter');
    });

$schemaVariable
    ->setName('schemaVariable')
    ->setCallback(fn () => new Schema());

$schema
    ->setName('schema')
    ->inject('http')
    ->inject('context')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('schemaVariable')
    ->setCallback(function (Http $http, Container $context, Request $request, Response $response, Database $dbForProject, Authorization $authorization, $schemaVariable) {
        $complexity = function (int $complexity, array $args) {
            $queries = Query::parseQueries($args['queries'] ?? []);
            $query = Query::getByType($queries, [Query::TYPE_LIMIT])[0] ?? null;
            $limit = $query ? $query->getValue() : APP_LIMIT_LIST_DEFAULT;

            return $complexity * $limit;
        };

        $attributes = function (int $limit, int $offset) use ($dbForProject, $authorization) {
            $attrs = $authorization->skip(fn () => $dbForProject->find('attributes', [
                Query::limit($limit),
                Query::offset($offset),
            ]));

            return \array_map(function ($attr) {
                return $attr->getArrayCopy();
            }, $attrs);
        };

        $urls = [
            'list' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents";
            },
            'create' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents";
            },
            'read' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
            },
            'update' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
            },
            'delete' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
            },
        ];

        $params = [
            'list' => function (string $databaseId, string $collectionId, array $args) {
                return ['queries' => $args['queries']];
            },
            'create' => function (string $databaseId, string $collectionId, array $args) {
                $id = $args['id'] ?? 'unique()';
                $permissions = $args['permissions'] ?? null;

                unset($args['id']);
                unset($args['permissions']);

                return [
                    'databaseId' => $databaseId,
                    'documentId' => $id,
                    'collectionId' => $collectionId,
                    'data' => $args,
                    'permissions' => $permissions,
                ];
            },
            'update' => function (string $databaseId, string $collectionId, array $args) {
                $documentId = $args['id'];
                $permissions = $args['permissions'] ?? null;

                unset($args['id']);
                unset($args['permissions']);

                return [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'documentId' => $documentId,
                    'data' => $args,
                    'permissions' => $permissions,
                ];
            },
        ];

        return $schemaVariable->build(
            $http,
            $request,
            $response,
            $context,
            $complexity,
            $attributes,
            $urls,
            $params,
        );
    });

$container->set($log);
$container->set($mode);
$container->set($user);
$container->set($team);
$container->set($plan);
$container->set($cache);
$container->set($pools);
$container->set($queue);
$container->set($geodb);
$container->set($hooks);
$container->set($locale);
$container->set($schema);
$container->set($github);
$container->set($logger);
$container->set($session);
$container->set($console);
$container->set($project);
$container->set($clients);
$container->set($servers);
$container->set($register);
$container->set($connections);
$container->set($localeCodes);
$container->set($dbForProject);
$container->set($dbForConsole);
$container->set($getConsoleDB);
$container->set($getProjectDB);
$container->set($queueForUsage);
$container->set($queueForMails);
$container->set($authorization);
$container->set($authentication);
$container->set($schemaVariable);
$container->set($queueForBuilds);
$container->set($queueForEvents);
$container->set($queueForAudits);
$container->set($deviceForLocal);
$container->set($deviceForFiles);
$container->set($promiseAdapter);
$container->set($queueForDeletes);
$container->set($deviceForBuilds);
$container->set($queueForDatabase);
$container->set($requestTimestamp);
$container->set($queueForMessaging);
$container->set($queueForFunctions);
$container->set($queueForMigrations);
$container->set($deviceForFunctions);
$container->set($passwordsDictionary);
$container->set($queueForCertificates);
