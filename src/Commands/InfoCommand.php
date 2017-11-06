<?php

namespace AcquiaCli\Commands;

use AcquiaCloudApi\Response\EnvironmentResponse;
use AcquiaCloudApi\Response\EnvironmentsResponse;
use Symfony\Component\Console\Helper\Table;

/**
 * Class InfoCommand
 * @package AcquiaCli\Commands
 */
class InfoCommand extends AcquiaCommand
{

    /**
     * Gets all code branches and tags associated with an application.
     *
     * @param string $uuid
     * @param string $match
     *
     * @command code:list
     */
    public function code($uuid, $match = null)
    {
        if (null !== $match) {
            $this->cloudapi->addQuery('filter', "name=@*${match}*");
        }
        $code = $this->cloudapi->code($uuid);
        $this->cloudapi->clearQuery();

        $output = $this->output();
        $table = new Table($output);
        $table->setHeaders(['Name', 'Tag']);

        foreach ($code as $branch) {
            $tag = $branch->flags->tag ? '✅' : '';
            $table
                ->addRows([
                    [
                        $branch->name,
                        $tag,
                    ],
                ]);
        }

        $table->render();
    }

    /**
     * Gets all tasks associated with a site.
     *
     * @param string $uuid
     * @param int    $limit  The maximum number of items to return.
     * @param string $filter
     * @param string $sort   Sortable by: 'name', 'title', 'created', 'completed', 'started'.
     * A leading "~" in the field indicates the field should be sorted in a descending order.
     *
     * @command task:list
     * @alias t:l
     */
    public function acquiaTasks($uuid, $limit = 100, $filter = null, $sort = '~created')
    {

        // Allows for limits and sort criteria.
        str_replace('~', '-', $sort);
        $this->cloudapi->addQuery('limit', $limit);
        $this->cloudapi->addQuery('sort', $sort);
        if (null !== $filter) {
            $this->cloudapi->addQuery('filter', "name=${filter}");
        }
        $tasks = $this->cloudapi->tasks($uuid);
        $this->cloudapi->clearQuery();

        $output = $this->output();
        $table = new Table($output);
        $table->setHeaders(['ID', 'Created', 'Name', 'Status', 'User']);

        $tz = $this->extraConfig['timezone'];
        $format = $this->extraConfig['format'];
        $timezone = new \DateTimeZone($tz);

        foreach ($tasks as $task) {
            $createdDate = new \DateTime($task->createdAt);
            $createdDate->setTimezone($timezone);

            $table
                ->addRows([
                    [
                        $task->uuid,
                        $createdDate->format($format),
                        $task->name,
                        $task->status,
                        $task->user->mail,
                    ],
                ]);
        }

        $table->render();
    }

    /**
     * Gets detailed information about a specific task
     *
     * @param string $uuid
     * @param string $taskUuid
     *
     * @command task:info
     * @alias t:i
     * @throws \Exception
     */
    public function acquiaTask($uuid, $taskUuid)
    {

        $tz = $this->extraConfig['timezone'];
        $format = $this->extraConfig['format'];

        $tasks = $this->cloudapi->tasks($uuid);

        foreach ($tasks as $task) {
            if ($taskUuid === $task->uuid) {

                $timezone = new \DateTimeZone($tz);

                $createdDate = new \DateTime($task->createdAt);
                $startedDate = new \DateTime($task->startedAt);
                $completedDate = new \DateTime($task->completed_at);

                $createdDate->setTimezone($timezone);
                $startedDate->setTimezone($timezone);
                $completedDate->setTimezone($timezone);

                $this->say('ID: ' . $task->uuid);
                $this->say('Sender: ' . $task->user->mail);
                $this->say('Description: ' . htmlspecialchars_decode($task->description));
                $this->say('Status: ' . $task->status);
                $this->say('Created: ' . $createdDate->format($format));
                $this->say('Started: ' . $startedDate->format($format));
                $this->say('Completed: ' . $completedDate->format($format));

                return;
            }
        }
        throw new \Exception('Unable to find Task ID');
    }

    /**
     * Shows all sites a user has access to.
     *
     * @command application:list
     * @alias app:list
     * @alias a:l
     */
    public function acquiaApplications()
    {
        $applications = $this->cloudapi->applications();

        $output = $this->output();
        $table = new Table($output);
        $table->setHeaders(['Name', 'UUID', 'Hosting ID']);
        foreach ($applications as $application) {
            $table
                ->addRows([
                    [
                        $application->name,
                        $application->uuid,
                        $application->hosting->id,
                    ],
                ]);
        }
        $table->render();
    }

    /**
     * Shows detailed information about a site.
     *
     * @param string $uuid
     *
     * @command application:info
     * @alias app:info
     * @alias a:i
     */
    public function acquiaApplicationInfo($uuid)
    {
        /** @var EnvironmentsResponse $environments */
        $environments = $this->cloudapi->environments($uuid);

        $output = $this->output();
        $table = new Table($output);
        $table->setHeaders(['Environment', 'ID', 'Branch/Tag', 'Domain(s)', 'Database(s)']);

        foreach ($environments as $environment) {
            /** @var EnvironmentResponse $environment */

            $databases = $this->cloudapi->environmentDatabases($environment->uuid);

            $dbNames = array_map(function($database) {
                return $database->name;
            }, $databases->getArrayCopy());

            $environmentName = $environment->label . ' (' . $environment->name . ')' ;
            if ($environment->flags->livedev) {
                $environmentName = '💻  ' . $environmentName;
            }

            if ($environment->flags->production_mode) {
                $environmentName = '🔒  ' . $environmentName;
            }

            $table
                ->addRows([
                    [
                        $environmentName,
                        $environment->uuid,
                        $environment->vcs->path,
                        implode("\n", $environment->domains),
                        implode("\n", $dbNames)
                    ],
                ]);
        }
        $table->render();
        $this->say('💻  indicates environment in livedev mode.');
        $this->say('🔒  indicates environment in production mode.');

    }

    /**
     * Shows detailed information about servers in an environment.
     *
     * @param string      $uuid
     * @param string|null $env
     *
     * @command environment:info
     * @alias env:info
     * @alias e:i
     */
    public function acquiaEnvironmentInfo($uuid, $env = null)
    {

        if (null !== $env) {
            $this->cloudapi->addQuery('filter', "name=${env}");
        }

        $environments = $this->cloudapi->environments($uuid);

        $this->cloudapi->clearQuery();

        foreach ($environments as $e) {
            $this->renderEnvironmentInfo($e);
        }

        $this->say("Web servers not marked 'Active' are out of rotation.");
        $this->say("Load balancer servers not marked 'Active' are hot spares");
        $this->say("Database servers not marked 'Primary' are the passive master");
    }

    /**
     * @param EnvironmentResponse $environment
     */
    protected function renderEnvironmentInfo(EnvironmentResponse $environment)
    {

        $environmentName = $environment->label;
        $environmentId = $environment->uuid;

        $this->yell("${environmentName} environment");
        $this->say("Environment ID: ${environmentId}");
        if ($environment->flags->livedev) {
            $this->say('💻  Livedev mode enabled.');
        }
        if ($environment->flags->production_mode) {
            $this->say('🔒  Production mode enabled.');
        }

        $output = $this->output();
        $table = new Table($output);
        // needs AZ?
        $table->setHeaders(['Role(s)', 'Name', 'FQDN', 'AMI', 'Region', 'IP', 'Memcache', 'Active', 'Primary', 'EIP']);

        $servers = $this->cloudapi->servers($environment->uuid);

        foreach ($servers as $server) {
            $memcache = $server->flags->memcache ? '✅' : '';
            $active = $server->flags->active_web || $server->flags->active_bal ? '✅' : '';
            $primaryDb = $server->flags->primary_db ? '✅' : '';
            $eip = $server->flags->elastic_ip ? '✅' : '';

            $table
                ->addRows([
                    [
                        implode(', ', $server->roles),
                        $server->name,
                        $server->hostname,
                        $server->amiType,
                        $server->region,
                        $server->ip,
                        $memcache,
                        $active,
                        $primaryDb,
                        $eip
                    ],
                ]);
        }

        $table->render();

    }

    /**
     * Shows SSH connection strings for specified environments.
     *
     * @param string      $uuid
     * @param string|null $env
     *
     * @command ssh:info
     */
    public function acquiaSshInfo($uuid, $env = null)
    {

        if (null !== $env) {
            $this->cloudapi->addQuery('filter', "name=${env}");
        }

        $environments = $this->cloudapi->environments($uuid);

        $this->cloudapi->clearQuery();

        foreach ($environments as $e) {
            $this->renderSshInfo($e);
        }
    }

    private function renderSshInfo(EnvironmentResponse $environment)
    {
        $environmentName = $environment->name;
        $ssh = $environment->sshUrl;
        $this->say("${environmentName}: ssh ${ssh}");
    }
}
