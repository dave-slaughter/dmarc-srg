<?php

/**
 * dmarc-srg - A php parser, viewer and summary report generator for incoming DMARC reports.
 * Copyright (C) 2020 Aleksey Andreev (liuch)
 *
 * Available at:
 * https://github.com/liuch/dmarc-srg
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of  MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * =========================
 *
 * This file contains the class Source
 *
 * @category API
 * @package  DmarcSrg
 * @author   Aleksey Andreev (liuch)
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU/GPLv3
 */

namespace Liuch\DmarcSrg\Sources;

/**
 * It's an abstract class for easy access to reports of a report source
 */
abstract class Source implements \Iterator
{
    public const SOURCE_DIRECTORY = 3;
    protected $data = null;

    /**
     * Constructor
     *
     * @param mixed Data to reach report files
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Iterator interface methods
     */
    abstract public function current();
    abstract public function key();
    abstract public function next(): void;
    abstract public function rewind(): void;
    abstract public function valid(): bool;

    /**
     * Called when the current report has been successfully processed.
     *
     * @return void
     */
    public function accepted(): void
    {
    }

    /**
     *  Called when the current report has been rejected.
     *
     * @return void
     */
    public function rejected(): void
    {
    }

    /**
     * Returns type of source, i.e. one of Source::SOURCE_* values
     *
     * @return int
     */
    abstract public function type(): int;
}
