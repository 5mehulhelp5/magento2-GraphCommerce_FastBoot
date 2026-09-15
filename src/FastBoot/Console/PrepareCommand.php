<?php

declare(strict_types=1);

namespace GraphCommerce\FastBoot\Console;

use GraphCommerce\FastBoot\Model\ObjectManager\AreaConfigLoader;
use GraphCommerce\FastBoot\Plugin\Config\SystemFromFile;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Module\Manager;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Prepare deployment artifacts, not business data. Run after DI compile in the new release. */
class PrepareCommand extends Command
{
    public function __construct(private ObjectManagerInterface $objects, private State $state, private AreaConfigLoader $loader, private Manager $modules)
    {
        parent::__construct('fastboot:prepare');
    }
    protected function configure(): void
    {
        $this->setDescription('Prepare area metadata, schema and requested config scopes after DI compilation.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (['frontend','adminhtml','graphql','crontab','webapi_rest','webapi_soap'] as $area) {
            if (is_file(\Magento\Framework\App\ObjectManager\ConfigLoader\Compiled::getFilePath($area))) {
                $this->loader->load($area, true);
            }
        }
        $this->state->setAreaCode('graphql');
        $this->objects->configure($this->loader->load('graphql'));
        if ($this->modules->isEnabled('GraphCommerce_FastBootGraphQl')) {
            $this->objects->create('Magento\\Framework\\GraphQl\\Config\\Data')->get(null);
            $this->objects->create('Magento\\Framework\\GraphQl\\Config\\Data')->get(null);
            $output->writeln('GraphQL schema prepared.');
        }
        $system = $this->objects->get(\Magento\Config\App\Config\Type\System::class);
        $stores = $this->objects->get(StoreManagerInterface::class)->getStores();
        for ($pass = 0;$pass < 2;$pass++) {
            $this->objects->get(SystemFromFile::class)->_resetState();
            $system->get('default');
            foreach ($stores as $store) {
                $system->get('stores/'.$store->getCode());
            }
        }
        $output->writeln('Area metadata and '.count($stores).' store config scopes prepared.');
        $output->writeln('Warm representative requests in the new FPM pool, then restart its FPM master/service to use the recorded preload list.');
        return Command::SUCCESS;
    }
}
