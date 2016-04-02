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
use xPaw\MinecraftQuery;
use xPaw\MinecraftQueryException;
use xPaw\MinecraftPing;
use xPaw\MinecraftPingException;

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
            $output->writeln('Calling webhook: '. $webhookUrl);

            /**
             * @var \Buzz\Message\Response $response
             */
            $response = $buzz->post($webhookUrl, array(), json_encode($webhookData));

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

        $output->writeln('');
        $output->writeln('Checking '. $server->getIp());

        $Query = new MinecraftQuery( );

        try {
            $output->writeln('Query...');

            $Query->Connect( $serverIp, $serverPort );

            $data = $Query->GetInfo();

            $webhookData['data'] = array(
                'hostname'   => $data['HostName'],
                'numplayers' => $data['Players'],
                'maxplayers' => $data['MaxPlayers'],
                'version'    => $data['Version'],
            );

            // some servers have version 'vWtfText 1.8.2'
            {
                $webhookData['data']['version'] = explode(' ', $webhookData['data']['version']);
                $webhookData['data']['version'] = array_pop($webhookData['data']['version']);
            }

            {
                $webhookData['data']['hostname'] = $this->codesToHtml($webhookData['data']['hostname']);
                $webhookData['data']['version']  = $this->codesToHtml($webhookData['data']['version']);
            }

            $output->writeln(
                sprintf('<info>Online %d/%d players. v%s</info>',
                    $webhookData['data']['numplayers'],
                    $webhookData['data']['maxplayers'],
                    $webhookData['data']['version']
                )
            );

            $webhookData['status'] = true;
        } catch(MinecraftQueryException $e) {
            $output->writeln('<error>'. 'Query Exception:'. PHP_EOL . $e->getMessage() .'</error>');

            unset($Query);

            {
                try {
                    $output->writeln('Ping...');

                    $Query = new MinecraftPing( $serverIp, $serverPort );

                    $data = $Query->Query();

                    $webhookData['data'] = array(
                        'hostname'   => $data['description'],
                        'numplayers' => $data['players']['online'],
                        'maxplayers' => $data['players']['max'],
                        'version'    => $data['version']['name'],
                    );

                    // some servers have version 'vWtfText 1.8.2'
                    {
                        $webhookData['data']['version'] = explode(' ', $webhookData['data']['version']);
                        $webhookData['data']['version'] = array_pop($webhookData['data']['version']);
                    }

                    {
                        $webhookData['data']['hostname'] = $this->codesToHtml($webhookData['data']['hostname']);
                        $webhookData['data']['version']  = $this->codesToHtml($webhookData['data']['version']);
                    }

                    $output->writeln(
                        sprintf('<info>Online %d/%d players. v%s</info>',
                            $webhookData['data']['numplayers'],
                            $webhookData['data']['maxplayers'],
                            $webhookData['data']['version']
                        )
                    );

                    $webhookData['status'] = true;
                } catch( MinecraftPingException $e ) {
                    $output->writeln('<error>'. 'Ping Exception:'. PHP_EOL . $e->getMessage() .'</error>');
                }

                if (isset($Query)) {
                    $Query->Close();
                }
            }
        }

        return $webhookData;
    }

    public function codesToHtml($raw)
    {
        $codes = array(
            '0' => array(
                'open' => '<span style="color:#000000;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '1' => array(
                'open' => '<span style="color:#0000AA;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '2' => array(
                'open' => '<span style="color:#00AA00;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '3' => array(
                'open' => '<span style="color:#00AAAA;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '4' => array(
                'open' => '<span style="color:#AA0000;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '5' => array(
                'open' => '<span style="color:#AA00AA;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '6' => array(
                'open' => '<span style="color:#FFAA00;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '7' => array(
                'open' => '<span style="color:#AAAAAA;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '8' => array(
                'open' => '<span style="color:#555555;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            '9' => array(
                'open' => '<span style="color:#5555FF;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            'a' => array(
                'open' => '<span style="color:#55FF55;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            'b' => array(
                'open' => '<span style="color:#55FFFF;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            'c' => array(
                'open' => '<span style="color:#FF5555;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            'd' => array(
                'open' => '<span style="color:#FF55FF;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            'e' => array(
                'open' => '<span style="color:#FFFF55;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            'f' => array(
                'open' => '<span style="color:#FFFFFF;">',
                'close' => '</span>',
                'group' => 'color',
            ),
            'k' => array(
                'open' => '<span data-obfuscated>',
                'close' => '</span>',
                'group' => 'style',
            ),
            'l' => array(
                'open' => '<b>',
                'close' => '</b>',
                'group' => 'style',
            ),
            'm' => array(
                'open' => '<s>',
                'close' => '</s>',
                'group' => 'style',
            ),
            'n' => array(
                'open' => '<u>',
                'close' => '</u>',
                'group' => 'style',
            ),
            'o' => array(
                'open' => '<i>',
                'close' => '</i>',
                'group' => 'style',
            ),
            'r' => array(
                'open' => '',
                'close' => '',
                'group' => '',
            ),
        );

        $html = '';
        $prevChar = null;
        $opening = $open = array( /* 'group' => $char */ );

        if (!is_string($raw)) return '???'; // fixme

        foreach (preg_split('/(?<!^)(?!$)/u', $raw) as $char) {
            if ($prevChar === '§' && isset($codes[$char])) { // it's code
                $group = $codes[$char]['group'];

                if ($char === 'r') { // close all
                    $opening = array();
                    unset($open[$group]);
                    while ($code = array_pop($open)) {
                        $html .= $codes[$code]['close'];
                    }
                } elseif (isset($open[$group])) { // close the tag and all tags opened after it
                    foreach (array_reverse($open, true) as $openGroup => $openChar) {
                        unset($open[$openGroup]);

                        $html .= $codes[ $openChar ]['close'];

                        if ($group === $openGroup) {
                            break;
                        }
                    }
                }

                unset($opening[$group]); // reset position
                $opening[$group] = $char; // append
            } elseif ($char !== '§') { // it's a text char
                if ($opening) { // open pending tags
                    foreach ($opening as $openingGroup => $openingChar) {
                        unset($opening[$openingGroup]);

                        if (isset($open[$openingGroup])) { // close the tag and all tags opened after it
                            foreach (array_reverse($open, true) as $openGroup => $openChar) {
                                unset($open[$openGroup]);

                                $html .= $codes[ $openChar ]['close'];

                                if ($openingGroup === $openGroup) {
                                    break;
                                }
                            }
                        }

                        $open[$openingGroup] = $openingChar;
                        $html .= $codes[$openingChar]['open'];
                    }
                }

                $html .= htmlspecialchars($char);
            }

            $prevChar = $char;
        }

        // close all open tags
        while ($code = array_pop($open)) {
            $html .= $codes[$code]['close'];
        }

        return $html;
    }
}
