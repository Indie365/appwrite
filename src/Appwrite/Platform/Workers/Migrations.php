<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Permission;
use Appwrite\Role;
use Exception;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Logger\Log;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\Appwrite as DestinationAppwrite;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite as SourceAppwrite;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Transfer;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Migrations extends Action
{
    protected Database $dbForProject;

    protected Database $dbForConsole;

    protected Document $project;

    public static function getName(): string
    {
        return 'migrations';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Migrations worker')
            ->inject('message')
            ->inject('dbForProject')
            ->inject('dbForConsole')
            ->inject('log')
            ->callback(fn (Message $message, Database $dbForProject, Database $dbForConsole, Log $log) => $this->action($message, $dbForProject, $dbForConsole, $log));
    }

    /**
     * @throws Exception
     */
    public function action(Message $message, Database $dbForProject, Database $dbForConsole, Log $log): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events    = $payload['events'] ?? [];
        $project   = new Document($payload['project'] ?? []);
        $migration = new Document($payload['migration'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }

        $this->dbForProject = $dbForProject;
        $this->dbForConsole = $dbForConsole;
        $this->project = $project;

        /**
         * Handle Event execution.
         */
        if (! empty($events)) {
            return;
        }

        $log->addTag('migrationId', $migration->getId());
        $log->addTag('projectId', $project->getId());

        $this->processMigration($migration, $log);
    }

    /**
     * @throws Exception
     */
    protected function processSource(Document $migration): Source
    {
        $source = $migration->getAttribute('source');
        $credentials = $migration->getAttribute('credentials');

        return match ($source) {
            Firebase::getName() => new Firebase(
                json_decode($credentials['serviceAccount'], true),
            ),
            Supabase::getName() => new Supabase(
                $credentials['endpoint'],
                $credentials['apiKey'],
                $credentials['databaseHost'],
                'postgres',
                $credentials['username'],
                $credentials['password'],
                $credentials['port'],
            ),
            NHost::getName() => new NHost(
                $credentials['subdomain'],
                $credentials['region'],
                $credentials['adminSecret'],
                $credentials['database'],
                $credentials['username'],
                $credentials['password'],
                $credentials['port'],
            ),
            SourceAppwrite::getName() => new SourceAppwrite(
                $credentials['projectId'],
                $credentials['endpoint'],
                $credentials['apiKey'],
            ),
            default => throw new \Exception('Invalid source type'),
        };
    }

    /**
     * @throws Exception
     */
    protected function processDestination(Document $migration): Destination
    {
        $destination = $migration->getAttribute('destination');
        $credentials = $migration->getAttribute('credentials');

        return match ($destination) {
            DestinationAppwrite::getName() => new DestinationAppwrite(
                $credentials['projectId'],
                $credentials['endpoint'],
                $credentials['apiKey'],
                $this->dbForProject,
                Config::getParam('collections', [])['databases']['collections'],
            ),
            default => throw new \Exception('Invalid destination type'),
        };
    }

    /**
     * @throws Authorization
     * @throws Structure
     * @throws Conflict
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function updateMigrationDocument(Document $migration, Document $project): Document
    {
        /** Trigger Realtime */
        $allEvents = Event::generateEvents('migrations.[migrationId].update', [
            'migrationId' => $migration->getId(),
        ]);

        $target = Realtime::fromPayload(
            event: $allEvents[0],
            payload: $migration,
            project: $project
        );

        Realtime::send(
            projectId: 'console',
            payload: $migration->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles'],
        );

        Realtime::send(
            projectId: $project->getId(),
            payload: $migration->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles'],
        );

        return $this->dbForProject->updateDocument('migrations', $migration->getId(), $migration);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     */
    protected function removeAPIKey(Document $apiKey): void
    {
        $this->dbForConsole->deleteDocument('keys', $apiKey->getId());
    }

    /**
     * @throws Authorization
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function generateAPIKey(Document $project): Document
    {
        $generatedSecret = bin2hex(\random_bytes(128));

        $key = new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'projectInternalId' => $project->getInternalId(),
            'projectId' => $project->getId(),
            'name' => 'Transfer API Key',
            'scopes' => [
                'users.read',
                'users.write',
                'teams.read',
                'teams.write',
                'databases.read',
                'databases.write',
                'collections.read',
                'collections.write',
                'documents.read',
                'documents.write',
                'buckets.read',
                'buckets.write',
                'files.read',
                'files.write',
                'functions.read',
                'functions.write',
            ],
            'expire' => null,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => $generatedSecret,
        ]);

        $this->dbForConsole->createDocument('keys', $key);
        $this->dbForConsole->purgeCachedDocument('projects', $project->getId());

        return $key;
    }

    /**
     * @throws Authorization
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    protected function processMigration(Document $migration, Log $log): void
    {
        $project = $this->project;
        $projectDocument = $this->dbForConsole->getDocument('projects', $project->getId());
        $tempAPIKey = $this->generateAPIKey($projectDocument);

        $transfer = $source = $destination = null;

        try {
            $migration = $this->dbForProject->getDocument('migrations', $migration->getId());

            if (
                $migration->getAttribute('source') === SourceAppwrite::getName() ||
                $migration->getAttribute('destination') === DestinationAppwrite::getName()
            ) {
                $credentials = $migration->getAttribute('credentials', []);

                $credentials['projectId'] = $credentials['projectId'] ?? $projectDocument->getId();
                $credentials['endpoint'] = $credentials['endpoint'] ?? 'http://appwrite/v1';
                $credentials['apiKey'] = $credentials['apiKey'] ?? $tempAPIKey['secret'];

                $migration->setAttribute('credentials', $credentials);
            }

            $migration->setAttribute('stage', 'processing');
            $migration->setAttribute('status', 'processing');
            $this->updateMigrationDocument($migration, $projectDocument);

            $log->addTag('type', $migration->getAttribute('source'));

            $source = $this->processSource($migration);
            $destination = $this->processDestination($migration);

            $source->report();

            $transfer = new Transfer(
                $source,
                $destination
            );

            /** Start Transfer */
            $migration->setAttribute('stage', 'migrating');
            $this->updateMigrationDocument($migration, $projectDocument);

            $transfer->run(
                $migration->getAttribute('resources'),
                function () use ($migration, $transfer, $projectDocument) {
                    $migration->setAttribute('resourceData', json_encode($transfer->getCache()));
                    $migration->setAttribute('statusCounters', json_encode($transfer->getStatusCounters()));
                    $this->updateMigrationDocument($migration, $projectDocument);
                },
                $migration->getAttribute('resourceId'),
                $migration->getAttribute('resourceType')
            );

            $destination->shutDown();
            $source->shutDown();

            $sourceErrors = $source->getErrors();
            $destinationErrors = $destination->getErrors();

            if (! empty($sourceErrors) || ! empty($destinationErrors)) {
                $migration->setAttribute('status', 'failed');
                $migration->setAttribute('stage', 'finished');

                $errorMessages = [];
                foreach ($sourceErrors as $error) {
                    /** @var $sourceErrors $error */
                    $message = "Error occurred while fetching '{$error->getResourceName()}:{$error->getResourceId()}' from source with message: '{$error->getMessage()}'";
                    if ($error->getPrevious()) {
                        $message .= " Message: ".$error->getPrevious()->getMessage() . " File: ".$error->getPrevious()->getFile() . " Line: ".$error->getPrevious()->getLine();
                    }

                    $errorMessages[] = $message;
                }
                foreach ($destinationErrors as $error) {
                    $message = "Error occurred while pushing '{$error->getResourceName()}:{$error->getResourceId()}' to destination with message: '{$error->getMessage()}'";

                    if ($error->getPrevious()) {
                        $message .= " Message: ".$error->getPrevious()->getMessage() . " File: ".$error->getPrevious()->getFile() . " Line: ".$error->getPrevious()->getLine();
                    }

                    /** @var MigrationException $error */
                    $errorMessages[] = $message;
                }

                $migration->setAttribute('errors', $errorMessages);
                $log->addExtra('migrationErrors', json_encode($errorMessages));
                $this->updateMigrationDocument($migration, $projectDocument);

                return;
            }

            $migration->setAttribute('status', 'completed');
            $migration->setAttribute('stage', 'finished');
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            Console::error($th->getTraceAsString());

            if (! $migration->isEmpty()) {
                $migration->setAttribute('status', 'failed');
                $migration->setAttribute('stage', 'finished');
                $migration->setAttribute('errors', [$th->getMessage()]);

                return;
            }

            if ($transfer) {
                $sourceErrors = $source->getErrors();
                $destinationErrors = $destination->getErrors();

                $errorMessages = [];
                foreach ($sourceErrors as $error) {
                    /** @var MigrationException $error */
                    $errorMessages[] = "Error occurred while fetching '{$error->getResourceName()}:{$error->getResourceId()}' from source with message '{$error->getMessage()}'";
                }
                foreach ($destinationErrors as $error) {
                    /** @var MigrationException $error */
                    $errorMessages[] = "Error occurred while pushing '{$error->getResourceName()}:{$error->getResourceId()}' to destination with message '{$error->getMessage()}'";
                }

                $migration->setAttribute('errors', $errorMessages);
                $log->addTag('migrationErrors', json_encode($errorMessages));
            }
        } finally {
            if (! $tempAPIKey->isEmpty()) {
                $this->removeAPIKey($tempAPIKey);
            }

            $this->updateMigrationDocument($migration, $projectDocument);

            if ($migration->getAttribute('status', '') === 'failed') {
                Console::error('Migration('.$migration->getInternalId().':'.$migration->getId().') failed, Project('.$this->project->getInternalId().':'.$this->project->getId().')');

                $destination->error();
                $source->error();

                throw new Exception('Migration failed');
            }
        }
    }
}
