# tex-wrapper

PHP class wrapping calls to PDFLatex to generate PDF files from LaTeX generated
by PHP itself.

Requires pdflatex to be installed, e.g. in Debian package texlive-latex-base.

[![Build Status](https://travis-ci.org/j-schumann/tex-wrapper.svg?branch=master)](https://travis-ci.org/j-schumann/tex-wrapper) [![Coverage Status](https://coveralls.io/repos/github/j-schumann/tex-wrapper/badge.svg?branch=master)](https://coveralls.io/github/j-schumann/tex-wrapper?branch=master)

## Usage

```php
// autogenerate filename for TeX file in temp dir
$wrapper = new TexWrapper\Wrapper();

// use existing TeX or store in custom path.
// the resulting PDF will have the same filename with ".pdf" appended
$wrapper = new TexWrapper\Wrapper('/my/path/texfile');

// generate the TeX file
$texContent = '\documentclass{article}
        \begin{document}
        \title{Introduction to \LaTeX{}}
        \author{Author Name}
        \maketitle
        \section{Introduction}
        Here is the text of your introduction.
        \end{document}';
$wrapper->saveTex($texContent);

// to customize log output or apply texfot to filter unnecessary messages
$wrapper->setCommand('texfot '.$wrapper->getCommand());

// build PDF file in the same path where the TeX file lives
$result = $wrapper->buildPdf();
if ($result) {
  echo "pdf file ".$wrapper->getPdfFile()." was created!";
} else {
  echo "pdf file wasn't generated!";
  var_dump($wrapper->getErrors());
}

// even when the PDF was generated there could be errors and pdflatex exited
// with an error code > 0
var_dump($wrapper->getErrors()); // returns array

// pdflatex always generates output, some warnings like missing fonts do not
// generate errors, the output is always saved:
var_dump($wrapper->getLog()); // returns string

// if you don't need the TeX file anymore
// it is automatically deleted on Wrapper destruction if no initial filename
// was set
$wrapper->deleteTex();
```