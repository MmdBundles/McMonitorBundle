<?php

namespace Mmd\Bundle\McMonitorBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ForceUTF8\Encoding as ForceUTF8Encoding;
use Buzz\Exception\ClientException as BuzzClientException;
use Mmd\Bundle\McMonitorBundle\Entity\Server;
use Mmd\Bundle\McMonitorBundle\Lib\MinecraftServerPing\MinecraftServerPing;
use Mmd\Bundle\McMonitorBundle\Lib\MinecraftServerPing\MinecraftServerPingException;

class CheckCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('mmd:mc-monitor:check')
            ->setDescription('Check servers and send info to the webhook')
            ->addArgument(
                'amount',
                InputArgument::OPTIONAL,
                'How many servers to check at once'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($amount = $input->getArgument('amount')) {
            $amount = intval($amount);
        } else {
            $amount = 1;
        }

        $webhookData = array(
            'secret'  => $this->getContainer()->getParameter('mmd.mc_monitor.secret'),
            'servers' => array(),
        );

        $now = new \DateTime();
        $em = $this->getContainer()->get('doctrine')->getManager();
        $repository = $this->getContainer()->get('doctrine')->getRepository('MmdMcMonitorBundle:Server');
        $servers = $repository->findNextForCheck($amount);

        foreach ($servers as $server) {
            /* @var \Mmd\Bundle\McMonitorBundle\Entity\Server $server */

            $server->setChecked($now);
            $em->flush();

            $webhookData['servers'][$server->getIp()] = $this->checkServer($server, $input, $output);
        }

        /**
         * @var \Buzz\Browser $buzz
         */
        $buzz = $this->getContainer()->get('buzz');

        $webhookUrl = $this->getContainer()->getParameter('mmd.mc_monitor.webhook');

        try {
            $output->writeln('');
            $output->writeln('Calling webhook: '. $webhookUrl .'?data=...');

            /**
             * @var \Buzz\Message\Response $response
             */
            $response = $buzz->get($webhookUrl .'?data='. urlencode(base64_encode(json_encode($webhookData))));

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

                if (isset($json_response['remove']) && is_array($json_response['remove'])) {
                    // removing specified servers

                    $output->writeln('');

                    foreach ($servers as $server) {
                        /* @var \Mmd\Bundle\McMonitorBundle\Entity\Server $server */

                        if (in_array($server->getIp(), $json_response['remove'])) {
                            $output->writeln('Removing '. $server->getIp());
                            $em->remove($server);
                        }
                    }

                    $em->flush();
                }
            } while(false);
        } catch(BuzzClientException $e) {
            $output->writeln('<error>'. '[Webhook Exception]'. PHP_EOL . $e->getMessage() .'</error>');
        }
    }

    protected function checkServer(Server $server, InputInterface $input, OutputInterface $output)
    {
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

        $webhookData = array(
            'status' => false,
            'data'   => array(),
        );

        try {
            $output->writeln('');
            $output->writeln('Checking '. $server->getIp());

            $checker = new MinecraftServerPing($serverIp, $serverPort, 3);

            if ($data = $checker->QueryOldPre17()) {
                $webhookData['data'] = array(
                    'hostname'   => $this->fixHostname($data['HostName']),
                    'numplayers' => $data['Players'],
                    'maxplayers' => $data['MaxPlayers'],
                    'version'    => $data['Version'],
                );

                $output->writeln(
                    sprintf('<info>Online %d/%d players. v%s</info>',
                        $webhookData['data']['numplayers'],
                        $webhookData['data']['maxplayers'],
                        $webhookData['data']['version']
                    )
                );

                $webhookData['status'] = true;
            } else {
                $output->writeln('<error>'. 'No data received' .'</error>');
            }
        } catch(MinecraftServerPingException $e) {
            $output->writeln('<error>'. '[Minecraft Query Exception]'. PHP_EOL . $e->getMessage() .'</error>');
        }

        return $webhookData;
    }

    protected function fixHostname($hostname)
    {
        return str_replace(
            array(
                '§0', '§1', '§2', '§3', '§4', '§5', '§6', '§7', '§8', '§9', '§a', '§b', '§c', '§d', '§e', '§f',
                '§k', '§l', '§m', '§n', '§o', '§r',
                '&0', '&1', '&2', '&3', '&4', '&5', '&6', '&7', '&8', '&9', '&a', '&b', '&c', '&d', '&e', '&f',
                '&k', '&l', '&m', '&n', '&o', '&r',
            ),
            array(''),
            ForceUTF8Encoding::toUTF8($hostname)
        );
    }
}