<?php

namespace Mmd\Bundle\McMonitorBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Mmd\Bundle\McMonitorBundle\Service\MinecraftQuery\MinecraftQueryException;
use ForceUTF8\Encoding as ForceUTF8Encoding;
use Buzz\Exception\ClientException as BuzzClientException;

class CheckNextServerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('mmd:mc-monitor:check-next-server')
            ->setDescription('Check next minecraft server')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->getContainer()->get('doctrine')->getManager();
        $repository = $this->getContainer()->get('doctrine')->getRepository('MmdMcMonitorBundle:Server');

        /**
         * @var \Mmd\Bundle\McMonitorBundle\Entity\Server $server
         */
        $server = $repository->findNextForCheck();

        if (!$server) {
            $output->writeln('No server found');
            return;
        }

        $server->setChecked(new \DateTime());
        $em->flush();

        /**
         * Separate ip and port
         */
        {
            $serverIp = explode(':', $server->getIp());
            $serverPort = 25565;

            if (count($serverIp) == 2) {
                // is "ip:port" format
                $serverPort = array_pop($serverIp);
                $serverIp = array_pop($serverIp);
            } else {
                // is "ip" format, without port (use default)
                $serverIp = implode('', $serverIp);
            }
        }

        $r = array(
            'secret' => $this->getContainer()->getParameter('mmd.mc_monitor.secret'),
            'ip'     => $server->getIp(),
            'status' => false,
            'data'   => array()
        );

        /**
         * @var \Mmd\Bundle\McMonitorBundle\Service\MinecraftQuery $minecraftQuery
         */
        $minecraftQuery = $this->getContainer()->get('mmd.mc_monitor.minecraft_query');

        try {
            $output->writeln('Checking '. $server->getIp());

            $minecraftQuery->Connect($serverIp, $serverPort);

            $r['status'] = true;
            $r['data']['info'] = $minecraftQuery->GetInfo();

            $r['data']['info']['hostname'] = ForceUTF8Encoding::toUTF8($r['data']['info']['hostname']);
            $r['data']['info']['_parsed']['hostname'] = str_replace(array(
                '§0', '§1', '§2', '§3', '§4', '§5', '§6', '§7', '§8', '§9', '§a', '§b', '§c', '§d', '§e', '§f',
                '§k', '§l', '§m', '§n', '§o', '§r'
            ), array(''), $r['data']['info']['hostname']);

            $output->writeln(
                sprintf('<info>Online %d/%d players</info>',
                    $r['data']['info']['numplayers'],
                    $r['data']['info']['maxplayers']
                )
            );
        } catch(MinecraftQueryException $e) {
            $output->writeln('<error>'. '[Minecraft Query Exception]'. PHP_EOL . $e->getMessage() .'</error>');
        }

        /**
         * @var \Buzz\Browser $buzz
         */
        $buzz = $this->getContainer()->get('buzz');

        $webhook = $this->getContainer()->getParameter('mmd.mc_monitor.webhook');

        try {
            $output->writeln('Calling webhook: '. $webhook .'?data=...');

            /**
             * @var \Buzz\Message\Response $response
             */
            $response = $buzz->get($webhook .'?data='. urlencode(json_encode($r)));

            $statusCode = $response->getStatusCode();

            do {
                if ($statusCode == 200) {
                    $output->writeln('<info>Webhook Succeeded</info>');
                } else {
                    $output->writeln('<error>Webhook returned the '. $statusCode .' status code</error>');
                    break;
                }

                $json_response = json_decode($response->getContent(), true);

                if ($json_response === null || !is_array($json_response)) {
                    break;
                }

                if (isset($json_response['remove']) && $json_response['remove']) {
                    // remove server
                    $output->writeln('Server removed');
                    $em->remove($server);
                    $em->flush();
                }
            } while(false);
        } catch(BuzzClientException $e) {
            $output->writeln('<error>'. '[Webhook Exception]'. PHP_EOL . $e->getMessage() .'</error>');
        }
    }
}