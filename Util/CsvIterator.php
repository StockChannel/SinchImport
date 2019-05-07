<?php

namespace SITC\Sinchimport\Util;

class CsvIterator extends \Magento\Framework\File\Csv {

    /**
     * @var string $currentFilename
     */
    protected $currentFilename;

    /**
     * File handle
     */
    protected $fh = null;

    /**
     * Open a file ready for iteration
     * @var string $file
     * @throws \Exception
     */
    public function openIter($file)
    {
        if(!is_null($this->fh) || $this->currentFilename != null){
            throw new \Exception("A file is already open for iteration");
        }

        if (!file_exists($file)) {
            throw new \Exception('File "' . $file . '" does not exist');
        }
        $this->fh = fopen($file, 'r');
        $this->currentFilename = $file;
    }

    /**
     * End the current iteration, closing the file
     */
    public function closeIter()
    {
        if(!is_null($this->fh)){
            fclose($this->fh);
            $this->fh = null;
        }
        $this->currentFilename = null;
    }

    /**
     * Retrieve CSV file data row by row
     *
     * @return  array
     * @throws \Exception
     */
    public function getIter()
    {
        if(is_null($this->fh) && is_null($this->currentFilename)){
            throw new \Exception("No file is currently open for iteration");
        }

        $rowData = fgetcsv($this->fh, $this->_lineLength, $this->_delimiter, $this->_enclosure);
        
        return $rowData;
    }

    /**
     * Take up to $count lines from file,
     * leaving the file open for further calls
     * 
     * @param string $file
     * @param int $count
     * @throws \Exception
     */
    public function take($count)
    {
        $current = 0;
        $data = [];
        while($rowData = $this->getIter()) {
            $data[] = $rowData;
            $current += 1;
            if($current == $count) {
                return $data;
            }
        }

        return $data;
    }
}