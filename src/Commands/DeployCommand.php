<?php

namespace AcquiaCli\Commands;

use Acquia\Cloud\Api\Response\Task;

class DeployCommand extends AcquiaCommand
{

    use \Boedah\Robo\Task\Drush\loadTasks;

    /**
     * Runs a deployment of a branch/tag and config/db update to the production environment.
     *
     * @command prod:deploy
     */
    public function acquiaDeployProd($site, $branch) {
        $this->yell('WARNING: DEPLOYING TO PROD');
        if ($this->confirm('Are you sure you want to deploy to prod?')) {
            $this->acquiaDeployEnv($site, 'prod', $branch);
        }
    }

    /**
     * Runs a deployment of a branch/tag and config/db update to a non-production environment.
     *
     * @command preprod:deploy
     */
    public function acquiaDeployPreProd($site, $environment, $branch) {
        if ($environment == 'prod') {
            throw new \Exception('Use the prod:acquia:deploy command for the production environment.');
        }

        $this->acquiaDeployEnv($site, $environment, $branch);
    }

    /**
     * Updates configuration and db in production.
     *
     * @command prod:config-update
     */
    public function acquiaConfigUpdateProd($site) {
        $this->yell('WARNING: UPDATING CONFIG ON PROD');
        if ($this->confirm('Are you sure you want to update prod config? This will overwrite your prod configuration.')) {
            $this->acquiaConfigUpdate($site, 'prod');
        }
    }

    /**
     * Updates configuration and db in a non-production environment.
     *
     * @command preprod:config-update
     */
    public function acquiaConfigUpdatePreProd($site, $environment) {

        if ($environment == 'prod') {
            throw new \Exception('Use the prod:acquia:prepare command for the production environment.');
        }

        $this->acquiaConfigUpdate($site, $environment);
    }

    /**
     * Updates configuration and db in all non-production environments.
     *
     * @command preprod:config-update:all
     */
    public function acquiaConfigUpdatePreProdAll($site) {
        $environments = $this->cloudapi->environments($site);

        foreach ($environments as $environment) {
            $env = $environment->name();
            if ($env == 'prod') {
                continue;
            }

            $this->acquiaConfigUpdate($site, $env);
        }
    }

    /**
     * Prepares the production environment for a deployment.
     *
     * @command prod:prepare
     */
    public function acquiaPrepareProd($site)
    {
        $databases = $this->cloudapi->environmentDatabases($site, 'prod');
        foreach ($databases as $database) {

            $db = $database->name();
            $this->backupDb($site, 'prod', $db);
        }
    }

    /**
     * Prepares a non-production environment for a deployment.
     *
     * @command preprod:prepare
     */
    public function acquiaPreparePreProd($site, $environmentFrom, $environmentTo)
    {

        if ($environmentTo == 'prod') {
            throw new \Exception('Use the acquia:prepare:prod command for the production environment.');
        }

        $this->backupAndMoveDbs($site, $environmentFrom, $environmentTo);
        $this->backupFiles($site, $environmentFrom, $environmentTo);
    }

    /**
     * Prepares all non-production environments for a deployment using prod as a source.
     *
     * @command preprod:prepare:all
     */
    public function acquiaPreparePreProdAll($site)
    {
        $environments = $this->cloudapi->environments($site);
        foreach ($environments as $environment) {
            $env = $environment->name();
            if ($env == 'prod') {
                continue;
            }

            $this->backupAndMoveDbs($site, 'prod', $env);
            $this->backupFiles($site, 'prod', $env);
        }
    }

    /**
     * Clears varnish cache for all domains in specific a specific pre-production environment.
     *
     * @command preprod:purgevarnish
     */
    public function acquiaPurgeVarnish($site, $environment) {

        if ($environment == 'prod') {
            throw new \Exception('Use the prod:acquia:purgevarnish command for the production environment.');
        }

        $this->acquiaPurgeVarnishForEnvironment($site, $environment);
    }

    /**
     * Clears varnish cache for all domains the production environment.
     *
     * @command prod:purgevarnish
     */
    public function acquiaPurgeVarnishProd($site) {

        $this->yell('WARNING: CLEARNING PROD VARNISH CACHE CAN RESULT IN REDUCTION IN PERFORMANCE');
        if ($this->confirm('Are you sure you want to clear prod varnish cache?')) {
            $this->acquiaPurgeVarnishForEnvironment($site, 'prod');
        }
    }

    /*************************************************************************/
    /*                         INTERNAL FUNCTIONS                            */
    /*************************************************************************/

    protected function backupAndMoveDbs($site, $environmentFrom, $environmentTo) {
        $databases = $this->cloudapi->environmentDatabases($site, $environmentFrom);
        foreach ($databases as $database) {

            $db = $database->name();

            $this->backupDb($site, $environmentFrom, $db);
            $this->backupDb($site, $environmentTo, $db);

            // Copy DB from prod to non-prod.
            $this->say("Moving DB (${db}) from ${environmentFrom} to ${environmentTo}");
            $task = $this->cloudapi->copyDatabase($site, $db, $environmentFrom, $environmentTo);
            $this->waitForTask($site, $task);
        }
    }

    protected function backupAllEnvironmentDbs($site, $environment) {
        $databases = $this->cloudapi->environmentDatabases($site, $environment);
        foreach ($databases as $database) {
            $db = $database->name();
            $this->backupDb($site, $environment, $db);
        }
    }

    protected function backupDb($site, $environment, $database) {
        // Run database backups.
        $this->say("Backing up DB (${database}) on ${environment}");
        $task = $this->cloudapi->createDatabaseBackup($site, $environment, $database);
        $this->waitForTask($site, $task);
    }

    protected function backupFiles($site, $environmentFrom, $environmentTo) {
        // Copy files from prod to non-prod.
        $this->say("Moving files from ${environmentFrom} to ${environmentTo}");
        $task = $this->cloudapi->copyFiles($site, $environmentFrom, $environmentTo);
        $this->waitForTask($site, $task);
    }

    protected function waitForTask($site, Task $task) {
        $taskId = $task->id();
        $complete = FALSE;

        while ($complete === FALSE) {
            $this->say('Waiting for task to complete...');
            $task = $this->cloudapi->task($site, $taskId);
            if ($task->completed()) {
                if ($task->state() !== 'done') {
                    throw new \Exception('Acquia task failed.');
                }
                $complete = TRUE;
                break;
            }
            sleep(1);

            // @TODO add a timeout here?
        }
        return TRUE;
    }

    protected function acquiaDeployEnv($site, $environment, $branch)
    {
        $this->backupAllEnvironmentDbs($site, $environment);
        $this->say("Deploying ${branch} to the ${environment} environment");
        $deployTask = $this->cloudapi->pushCode($site, $environment, $branch);
        $this->waitForTask($site, $deployTask);
        $this->acquiaConfigUpdate($site, $environment);
        $this->acquiaPurgeVarnishForEnvironment($site, $environment);
    }

    protected function acquiaConfigUpdate($site, $environment) {
        $site = $this->cloudapi->site($site);
        $siteName = $site->unixUsername();

        $task = $this->taskDrushStack()
            ->stopOnFail()
            ->siteAlias("@${siteName}.${environment}")
            ->clearCache('drush')
            ->drush("cache-rebuild")
            ->updateDb()
            ->drush(['pm-enable', 'config_split'])
            ->drush(['config-import', 'sync'])
            ->drush("cache-rebuild")
            ->run();

    }

    protected function acquiaPurgeVarnishForEnvironment($site, $environment) {

        $domains = $this->cloudapi->domains($site, $environment);
        foreach ($domains as $domain) {
            $domainName = $domain->name();
            $this->say("Purging varnish cache for ${domainName} in ${environment} environment");
            $task = $this->cloudapi->purgeVarnishCache($site, $environment, $domainName);
            $this->waitForTask($site, $task);
        }
    }
}



