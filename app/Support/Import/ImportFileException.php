<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * The file as a whole cannot be imported — wrong headings, no rows, too many
 * rows. The message is written for the person who uploaded it.
 */
class ImportFileException extends RuntimeException {}
