<?php

namespace Modules\Workspace\FloorMap;

use RuntimeException;

/** The map was saved by someone else since this editor loaded it. */
class FloorMapConflict extends RuntimeException {}
