<?php

namespace SITC\Sinchimport\Model\Config\Source;

class Serverlist implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 'ftp.stockinthechannel.com',
                'label' => 'UK - ftp.stockinthechannel.com'
            ],
            [
                'value' => 'ftpus.stockinthechannel.com',
                'label' => 'USA - ftpus.stockinthechannel.com'
            ],
            [
                'value' => 'ftp.canalstock.es',
                'label' => 'Spain - ftp.canalstock.es'
            ],
            [
                'value' => 'ftp.canalstock.mx',
                'label' => 'Mexico - ftp.canalstock.mx'
            ],
            [
                'value' => 'ftp.stockradar.be',
                'label' => 'Belgium - ftp.stockradar.be'
            ],
            [
                'value' => 'ftpau.stockinthechannel.com',
                'label' => 'Australia - ftpau.stockinthechannel.com'
            ],
            [
                'value' => 'ftpfr.stockinthechannel.com',
                'label' => 'France - ftpfr.stockinthechannel.com'
            ],
            [
                'value' => 'ftpit.stockinthechannel.com',
                'label' => 'Italy - ftpit.stockinthechannel.com'
            ],
            [
                'value' => 'ftpnl.stockinthechannel.com',
                'label' => 'Holland - ftpnl.stockinthechannel.com'
            ],
            [
                'value' => 'ftpde.stockinthechannel.com',
                'label' => 'Germany - ftpde.stockinthechannel.com'
            ],
            [
                'value' => 'ftpse.stockinthechannel.com',
                'label' => 'Sweden - ftpse.stockinthechannel.com'
            ],
            [
                'value' => 'ftp.ca.stockinthechannel.com',
                'label' => 'Canada - ftp.ca.stockinthechannel.com'
            ],
            [
                'value' => 'ftpdemo.stockinthechannel.com',
                'label' => 'Demo - ftpdemo.stockinthechannel.com'
            ],
            [
                'value' => 'ftp.sandbox.stockinthechannel.com',
                'label' => 'Sandbox - ftp.sandbox.stockinthechannel.com'
            ]
        ];
    }
    
    public function toArray()
    {
        return [
            'ftp.stockinthechannel.com'     => 'UK - ftp.stockinthechannel.com',
            'ftpus.stockinthechannel.com'   => 'USA - ftpus.stockinthechannel.com',
            'ftp.canalstock.es'             => 'Spain - ftp.canalstock.es',
            'ftp.canalstock.mx'             => 'Mexico - ftp.canalstock.mx',
            'ftp.stockradar.be'             => 'Belgium - ftp.stockradar.be',
            'ftpau.stockinthechannel.com'   => 'Australia - ftpau.stockinthechannel.com',
            'ftpfr.stockinthechannel.com'   => 'France - ftpfr.stockinthechannel.com',
            'ftpit.stockinthechannel.com'   => 'Italy - ftpit.stockinthechannel.com',
            'ftpnl.stockinthechannel.com'   => 'Holland - ftpnl.stockinthechannel.com',
            'ftpde.stockinthechannel.com'   => 'Germany - ftpde.stockinthechannel.com',
            'ftpse.stockinthechannel.com'   => 'Sweden - ftpse.stockinthechannel.com',
            'ftp.ca.stockinthechannel.com' => 'Canada - ftp.ca.stockinthechannel.com',
            'ftpdemo.stockinthechannel.com' => 'Demo - ftpdemo.stockinthechannel.com',
            'ftp.sandbox.stockinthechannel.com' => 'Sandbox - ftp.sandbox.stockinthechannel.com'
        ];
    }
}
