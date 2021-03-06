<?php

declare(strict_types=1);

/**
 * @copyright   (c) 2017, Vrok
 * @license     MIT License (http://www.opensource.org/licenses/mit-license.php)
 * @author      Jakob Schumann <schumann@vrok.de>
 */

namespace TexWrapper;

/**
 * Stores the given LaTeX and generates a PDF file from it.
 */
class Wrapper
{
    /**
     * Command to use to execute PDFLaTeX or other engine like LuaLaTeX.
     *
     * nonstopmode: shows the errors and does not wait for interaction,
     *     batchmode would show no error/warnings at all
     * output-directory: without it pdflatex would put the files
     *     (*.log, *.aux, *.pdf) into the current working directory
     * file-line-error: show filename:line before error messages
     */
    protected string $command = 'pdflatex --file-line-error'
                .' --interaction=nonstopmode --output-directory=%dir% %file%';

    /**
     * Filename used to store the TeX.
     */
    protected string $filename;

    /**
     * Flag for the destructor to clean up if true.
     */
    protected bool $isTempfile = false;

    /**
     * List of errors that occured while building the PDF.
     */
    protected array $errors = [];

    /**
     * Console output generated by the latex and post-processing commands.
     */
    protected string $log = '';

    /**
     * Class constructor - stores the given filename.
     *
     * @param string $filename (optional) if given the TEX is stored in this file and
     *                         kept, if empty a temporary file is used and deleted on destruction
     */
    public function __construct(string $filename = null)
    {
        if (!$filename) {
            // tempnam requires a dir, we can not use ini_get('upload_tmp_dir')
            // as this would not be set in CLI, sys_get_temp_dir() can probably
            // not be changed to return a vhost specific path,
            // @see http://stackoverflow.com/questions/13186069/sys-get-temp-dir-in-shared-hosting-environment
            // tempnam falls back to the system tmp if the given directory does
            // not exist but may have problems if it is outside the open_basedir
            // restriction
            $dir = sys_get_temp_dir();
            $filename = tempnam($dir, 'textemp');
            $this->isTempfile = true;
        }

        $this->filename = $filename;
    }

    /**
     * If the instance created a temporary file remove it now.
     */
    public function __destruct()
    {
        if ($this->isTempfile) {
            $this->deleteTex();
        }
    }

    /**
     * Returns the current latex command.
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Allows to set a custom latex command. May contain full path if it's not
     * within the $PATH environment. Can be used to modify the command, e.g. if
     * you have installed texfot. %dir% and %file% are replaced with the target
     * directory and target filename (including path).
     */
    public function setCommand(string $cmd): self
    {
        $this->command = $cmd;

        return $this;
    }

    /**
     * Stores the given Tex content in the current file.
     *
     * @return bool true if the file was written, else false
     */
    public function saveTex(string $tex): bool
    {
        return false !== file_put_contents($this->filename, $tex);
    }

    /**
     * Deletes the tex file for cleanup.
     */
    public function deleteTex()
    {
        file_exists($this->filename) && unlink($this->filename);
    }

    /**
     * Generates a pdf file from the current LaTeX file.
     *
     * @return bool true if the PDF file was written, else false
     */
    public function buildPdf(): bool
    {
        $this->errors = [];

        if (file_exists($this->filename.'.pdf')) {
            if (!unlink($this->filename.'.pdf')) {
                $this->errors['pdf'] = 'Old PDF file could not be deleted';

                return false;
            }
        }

        $command = str_replace(
            ['%dir%', '%file%'],
            [dirname($this->filename), $this->filename],
            $this->command
        );

        // execute multiple times to resolve all document references
        $output = $return = null;
        exec($command);
        exec($command);
        exec($command, $output, $return);

        // 127 == command not found
        if (127 == $return) {
            $this->errors['engine'] =
                'Command not found, engine is not installed or not within the $PATH!';

            return false;
        }

        $this->log = implode("\n", $output);
        // latex command returned an exit state > 0 or the PDF wasn't created
        // (e.g. lualatex fails with "fix your writable cache path" but exit
        // code is still 0...)
        if (0 != $return || !file_exists($this->filename.'.pdf')) {
            // return the complete output for debugging
            $this->errors['engine'] = $this->log;
        }

        // remove unwanted additional files
        file_exists($this->filename.'.out') && unlink($this->filename.'.out');
        file_exists($this->filename.'.aux') && unlink($this->filename.'.aux');
        file_exists($this->filename.'.log') && unlink($this->filename.'.log');

        // good hint for missing packages
        $missingFonts = dirname($this->filename).'/missfont.log';
        if (file_exists($missingFonts)) {
            $this->errors['missingFonts'] = file_get_contents($missingFonts);
            unlink($missingFonts);
        }

        // sometimes the texput.log is generated, e.g. when the input file does not exist
        file_exists(dirname($this->filename).'/texput.log')
            && unlink(dirname($this->filename).'/texput.log');

        return file_exists($this->filename.'.pdf');
    }

    /**
     * Allows to execute a custom post-processing command.
     * %dir% and %file% are replaced with the target directory and target
     * filename (including path).
     */
    public function postProcess(string $command): bool
    {
        if (!file_exists($this->filename.'.pdf')) {
            $this->errors['postProcessor'] =
                    'PDF file not found, build not started or failed?';

            return false;
        }

        $outputPost = $returnPost = null;
        $postCmd = str_replace(
            ['%dir%', '%file%'],
            [dirname($this->filename), $this->filename.'.pdf'],
            $command
        );

        exec($postCmd, $outputPost, $returnPost);
        if (0 != $returnPost) {
            // the post-processing returned an exit state > 0,
            // return the complete output for debugging
            $this->errors['postProcessor'] = implode("\n", $outputPost);

            return false;
        }

        if (!file_exists($this->filename.'.pdf')) {
            $this->errors['postProcessor'] = 'post-processing removed the PDF!';

            return false;
        }

        return true;
    }

    /**
     * Retrieve all messages from the last PDF build (and post-processing) if
     * some fatal error occured.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Retrieve the console output generated by latex, can contain warnings
     * like missing fonts or smaller tex errors that were ignored by the engine.
     */
    public function getLog(): string
    {
        return $this->log;
    }

    /**
     * Retrieve the autogenerated or configured file name.
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Returns the name of the generated PDF file.
     *
     * @return string the pdf file name or NULL if it was not (yet) generated
     */
    public function getPdfFile(): ?string
    {
        $filename = $this->filename.'.pdf';

        return file_exists($filename) ? $filename : null;
    }
}
