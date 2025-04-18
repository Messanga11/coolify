<?php

namespace App\Actions\Database;

use App\Helpers\SslHelper;
use App\Models\SslCertificate;
use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartPostgresql
{
    use AsAction;

    public StandalonePostgresql $database;

    public array $commands = [];

    public array $init_scripts = [];

    public string $configuration_dir;

    private ?SslCertificate $ssl_certificate = null;

    public function handle(StandalonePostgresql $database)
    {
        $this->database = $database;
        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir().'/'.$container_name;
        if (isDev()) {
            $this->configuration_dir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/'.$container_name;
        }

        $this->commands = [
            "echo 'Starting database.'",
            "echo 'Creating directories.'",
            "mkdir -p $this->configuration_dir",
            "mkdir -p $this->configuration_dir/docker-entrypoint-initdb.d/",
            "echo 'Directories created successfully.'",
        ];

        if (! $this->database->enable_ssl) {
            $this->commands[] = "rm -rf $this->configuration_dir/ssl";

            $this->database->sslCertificates()->delete();

            $this->database->fileStorages()
                ->where('resource_type', $this->database->getMorphClass())
                ->where('resource_id', $this->database->id)
                ->get()
                ->filter(function ($storage) {
                    return in_array($storage->mount_path, [
                        '/var/lib/postgresql/certs/server.crt',
                        '/var/lib/postgresql/certs/server.key',
                    ]);
                })
                ->each(function ($storage) {
                    $storage->delete();
                });
        } else {
            $this->commands[] = "echo 'Setting up SSL for this database.'";
            $this->commands[] = "mkdir -p $this->configuration_dir/ssl";

            $server = $this->database->destination->server;
            $caCert = SslCertificate::where('server_id', $server->id)->where('is_ca_certificate', true)->first();

            if (! $caCert) {
                $server->generateCaCertificate();
                $caCert = SslCertificate::where('server_id', $server->id)->where('is_ca_certificate', true)->first();
            }

            if (! $caCert) {
                $this->dispatch('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');

                return;
            }

            $this->ssl_certificate = $this->database->sslCertificates()->first();

            if (! $this->ssl_certificate) {
                $this->commands[] = "echo 'No SSL certificate found, generating new SSL certificate for this database.'";
                $this->ssl_certificate = SslHelper::generateSslCertificate(
                    commonName: $this->database->uuid,
                    resourceType: $this->database->getMorphClass(),
                    resourceId: $this->database->id,
                    serverId: $server->id,
                    caCert: $caCert->ssl_certificate,
                    caKey: $caCert->ssl_private_key,
                    configurationDir: $this->configuration_dir,
                    mountPath: '/var/lib/postgresql/certs',
                );
            }
        }

        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->database->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();
        $this->generate_init_scripts();
        $this->add_custom_conf();

        $docker_compose = [
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'container_name' => $container_name,
                    'environment' => $environment_variables,
                    'restart' => RESTART_MODE,
                    'networks' => [
                        $this->database->destination->network,
                    ],
                    'labels' => defaultDatabaseLabels($this->database)->toArray(),
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            "psql -U {$this->database->postgres_user} -d {$this->database->postgres_db} -c 'SELECT 1' || exit 1",
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 10,
                        'start_period' => '5s',
                    ],
                    'mem_limit' => $this->database->limits_memory,
                    'memswap_limit' => $this->database->limits_memory_swap,
                    'mem_swappiness' => $this->database->limits_memory_swappiness,
                    'mem_reservation' => $this->database->limits_memory_reservation,
                    'cpus' => (float) $this->database->limits_cpus,
                    'cpu_shares' => $this->database->limits_cpu_shares,
                ],
            ],
            'networks' => [
                $this->database->destination->network => [
                    'external' => true,
                    'name' => $this->database->destination->network,
                    'attachable' => true,
                ],
            ],
        ];

        if (filled($this->database->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->database->limits_cpuset);
        }

        if ($this->database->destination->server->isLogDrainEnabled() && $this->database->isLogDrainEnabled()) {
            $docker_compose['services'][$container_name]['logging'] = generate_fluentd_configuration();
        }

        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        }

        $docker_compose['services'][$container_name]['volumes'] ??= [];

        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                $persistent_storages
            );
        }

        if (count($persistent_file_volumes) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                $persistent_file_volumes->map(function ($item) {
                    return "$item->fs_path:$item->mount_path";
                })->toArray()
            );
        }

        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }

        if (count($this->init_scripts) > 0) {
            foreach ($this->init_scripts as $init_script) {
                $docker_compose['services'][$container_name]['volumes'] = array_merge(
                    $docker_compose['services'][$container_name]['volumes'],
                    [[
                        'type' => 'bind',
                        'source' => $init_script,
                        'target' => '/docker-entrypoint-initdb.d/'.basename($init_script),
                        'read_only' => true,
                    ]]
                );
            }
        }

        if (filled($this->database->postgres_conf)) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                [[
                    'type' => 'bind',
                    'source' => $this->configuration_dir.'/custom-postgres.conf',
                    'target' => '/etc/postgresql/postgresql.conf',
                    'read_only' => true,
                ]]
            );
            $docker_compose['services'][$container_name]['command'] = [
                'postgres',
                '-c',
                'config_file=/etc/postgresql/postgresql.conf',
            ];
        }

        if ($this->database->enable_ssl) {
            $docker_compose['services'][$container_name]['command'] = [
                'postgres',
                '-c',
                'ssl=on',
                '-c',
                'ssl_cert_file=/var/lib/postgresql/certs/server.crt',
                '-c',
                'ssl_key_file=/var/lib/postgresql/certs/server.key',
            ];
        }

        // Add custom docker run options
        $docker_run_options = convertDockerRunToCompose($this->database->custom_docker_run_options);
        $docker_compose = generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $this->database->destination->network);

        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d | tee $this->configuration_dir/docker-compose.yml > /dev/null";
        $readme = generate_readme_file($this->database->name, now());
        $this->commands[] = "echo '{$readme}' > $this->configuration_dir/README.md";
        $this->commands[] = "echo 'Pulling {$database->image} image.'";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml pull";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml up -d";
        if ($this->database->enable_ssl) {
            $this->commands[] = executeInDocker($this->database->uuid, "chown {$this->database->postgres_user}:{$this->database->postgres_user} /var/lib/postgresql/certs/server.key /var/lib/postgresql/certs/server.crt");
        }
        $this->commands[] = "echo 'Database started.'";

        return remote_process($this->commands, $database->destination->server, callEventOnFinish: 'DatabaseStatusChanged');
    }

    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path !== '' && $persistentStorage->host_path !== null) {
                $local_persistent_volumes[] = $persistentStorage->host_path.':'.$persistentStorage->mount_path;
            } else {
                $volume_name = $persistentStorage->name;
                $local_persistent_volumes[] = $volume_name.':'.$persistentStorage->mount_path;
            }
        }

        return $local_persistent_volumes;
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        $local_persistent_volumes_names = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;
            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }

        return $local_persistent_volumes_names;
    }

    private function generate_environment_variables()
    {
        $environment_variables = collect();
        foreach ($this->database->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->real_value");
        }

        if ($environment_variables->filter(fn ($env) => str($env)->contains('POSTGRES_USER'))->isEmpty()) {
            $environment_variables->push("POSTGRES_USER={$this->database->postgres_user}");
        }
        if ($environment_variables->filter(fn ($env) => str($env)->contains('PGUSER'))->isEmpty()) {
            $environment_variables->push("PGUSER={$this->database->postgres_user}");
        }

        if ($environment_variables->filter(fn ($env) => str($env)->contains('POSTGRES_PASSWORD'))->isEmpty()) {
            $environment_variables->push("POSTGRES_PASSWORD={$this->database->postgres_password}");
        }

        if ($environment_variables->filter(fn ($env) => str($env)->contains('POSTGRES_DB'))->isEmpty()) {
            $environment_variables->push("POSTGRES_DB={$this->database->postgres_db}");
        }

        add_coolify_default_environment_variables($this->database, $environment_variables, $environment_variables);

        return $environment_variables->all();
    }

    private function generate_init_scripts()
    {
        $this->commands[] = "rm -rf $this->configuration_dir/docker-entrypoint-initdb.d/*";

        if (blank($this->database->init_scripts) || count($this->database->init_scripts) === 0) {
            return;
        }

        foreach ($this->database->init_scripts as $init_script) {
            $filename = data_get($init_script, 'filename');
            $content = data_get($init_script, 'content');
            $content_base64 = base64_encode($content);
            $this->commands[] = "echo '{$content_base64}' | base64 -d | tee $this->configuration_dir/docker-entrypoint-initdb.d/{$filename} > /dev/null";
            $this->init_scripts[] = "$this->configuration_dir/docker-entrypoint-initdb.d/{$filename}";
        }
    }

    private function add_custom_conf()
    {
        $filename = 'custom-postgres.conf';
        $config_file_path = "$this->configuration_dir/$filename";

        if (blank($this->database->postgres_conf)) {
            $this->commands[] = "rm -f $config_file_path";

            return;
        }

        $content = $this->database->postgres_conf;
        if (! str($content)->contains('listen_addresses')) {
            $content .= "\nlisten_addresses = '*'";
            $this->database->postgres_conf = $content;
            $this->database->save();
        }
        $content_base64 = base64_encode($content);
        $this->commands[] = "echo '{$content_base64}' | base64 -d | tee $config_file_path > /dev/null";
    }
}
