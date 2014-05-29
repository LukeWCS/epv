<?php
/**
 *
 * @package EPV
 * @copyright (c) 2014 phpBB Group
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */
namespace epv\Tests\Tests;

use epv\Output\Output;
use epv\Output\OutputInterface;
use epv\Tests\BaseTest;

class epv_test_validate_directory_structure  extends BaseTest{
    // $this->totalDirectoryTests is sizeof this.
    private $requiredFiles = array(
        'license.txt',
        'composer.json',
        'ext.php',
    );

    public function __construct($debug, OutputInterface $output, $basedir)
    {
        parent::__construct($debug, $output, $basedir);

        $this->directory = true;

        $this->totalDirectoryTests = sizeof($this->requiredFiles);
    }

    public function validateDirectory(array $dirList)
    {
        foreach ($this->requiredFiles as $file)
        {
            // TODO: Depending on the specs for extensions.
            $found = false;

            foreach ($dirList as $dir)
            {
                if (basename($dir) == $file)
                {
                    $found = true;
                    break;
                }
            }
            if (!$found)
            {
                $this->output->addMessage(Output::ERROR, sprintf("The required file %s is missing from the extension package.", $file));
            }
            else
            {
                $this->output->printErrorLevel();
            }
        }
    }

    public function testName()
    {
        return "Validate directory structure";
    }
}
