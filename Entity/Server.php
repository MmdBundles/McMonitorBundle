<?php

namespace Mmd\Bundle\McMonitorBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;

/**
 * Monitored Server
 *
 * @ORM\Table(name="mmd_mc_monitor_server", indexes={@index(name="checked", columns={"checked"})})
 * @ORM\Entity(repositoryClass="Mmd\Bundle\McMonitorBundle\Entity\ServerRepository")
 * @ORM\HasLifecycleCallbacks()
 */
class Server
{
    /**
     * @var string
     *
     * @ORM\Id
     * @ORM\Column(name="ip", type="string", length=64)
     */
    private $ip;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="checked", type="datetime")
     */
    private $checked;


    /**
     * @ORM\PrePersist
     */
    public function setCheckedValue()
    {
        $this->checked = new \DateTime();
        $this->checked->setTimestamp(0);
    }


    /**
     * Set ip
     *
     * @param string $ip
     * @return Server
     */
    public function setIp($ip)
    {
        $this->ip = $ip;

        return $this;
    }

    /**
     * Get ip
     *
     * @return string 
     */
    public function getIp()
    {
        return $this->ip;
    }

    /**
     * Set checked
     *
     * @param \DateTime $checked
     * @return Server
     */
    public function setChecked($checked)
    {
        $this->checked = $checked;

        return $this;
    }

    /**
     * Get checked
     *
     * @return \DateTime 
     */
    public function getChecked()
    {
        return $this->checked;
    }
}
