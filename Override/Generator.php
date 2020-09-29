<?php
namespace SITC\Sinchimport\Override;

/**
 * Class Generator
 * Overrides Magento code generation to ensure that Magento doesn't try to generate Smile classes if they're missing
 * Intercepts Magento\Framework\Code\Generator
 * @package SITC\Sinchimport\Override
 */
class Generator extends \Magento\Framework\Code\Generator
{
    public function generateClass($className)
    {
        //Prevent Magento from generating classes that we use to detect the Smile ES module
        if ($className === 'Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory') {
            return parent::GENERATION_SKIP;
        }
        return parent::generateClass($className);
    }
}