<?php

namespace Mmd\Bundle\McMonitorBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Mmd\Bundle\McMonitorBundle\Entity\Server;

class ApiV1Controller extends Controller
{
    protected $ipOrHostWithPortRegex = '/^((([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|(([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])\.)*([A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9\-]*[A-Za-z0-9]))(\:\d{2,5})?$/'; # http://stackoverflow.com/questions/106179

    protected function isSecretValid($secret)
    {
        return $this->container->getParameter('mmd.mc_monitor.secret') === $secret;
    }

    /**
     * Add server to monitoring
     */
    public function addAction(Request $request, $secret, $ip)
    {
        $r = array(
            'status'  => false,
            'message' => ''
        );

        do {
            if (!$this->isSecretValid($secret)) {
                $r['message'] = 'Invalid secret';
                break;
            }

            if (!preg_match($this->ipOrHostWithPortRegex, $ip)) {
                $r['message'] = 'Invalid ip';
                break;
            }

            $r['status']  = true;
            $r['message'] = 'Server added';

            if ($this->getDoctrine()->getRepository('MmdMcMonitorBundle:Server')->find($ip)) {
                // already exists
                break;
            }

            $server = new Server();
            $server->setIp($ip);

            $em = $this->getDoctrine()->getManager();
            $em->persist($server);
            $em->flush();
        } while(false);

        return new JsonResponse($r);
    }
}
