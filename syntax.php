<?php

/**
 * Plugin AVMathTable
 *
 * Adds math to columns for Dokuwiki tables.
 * Supported Math:
 *    AVG - Calculate average of the column.
 *    SUM - Calculate total of the entire column so far.
 *    TOT - Calculate the total since the last total (TOT) was shown. Like a section subtotal.
 *    ROW - Works like SUM but for the current row. Sums the cells to the left of the one where this command is called. Does not work with other commands in the row. Ie can't sum a row of totals (yet).
 *    CNT - Number of numeric values in the column above this cell.
 *    MAX - Maximum value in the column.
 *    MIN - Minimum value in the column.
 *
 * USAGE:
<mathtable>
^ Name ^ Deposited ^ Balance ^
| John | 25        | 500     |
| Mary | 40        | 680     |
| Lex  | 10        | 140     |
| TOTAL| =AVG      | =SUM    |
</mathtable>
 *
 * @license    GPL-2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
 * @author     Sherri W. (http://syntaxseed.com)
 */

if (!defined('DOKU_INC')) {
    define('DOKU_INC', realpath(dirname(__FILE__) . '/../../') . '/');
}
if (!defined('DOKU_PLUGIN')) {
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
}


/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_avmathtable extends DokuWiki_Syntax_Plugin
{
    private array $infoTable = [];

    /**
     * What kind of syntax are we?
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * Where to sort in?
     */
    public function getSort()
    {
        return 999;
    }


    /**
     * Connect pattern to lexer
     */
    public function connectTo($mode)
    {
        $this->Lexer->addEntryPattern('\<mathtable\>', $mode, 'plugin_avmathtable');
    }

    public function postConnect()
    {
        $this->Lexer->addExitPattern('\</mathtable\>', 'plugin_avmathtable');
    }


    /**
     * Handle the match
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        switch ($state) {
            case DOKU_LEXER_ENTER:
                return array($state, '');
            case DOKU_LEXER_MATCHED:
                break;
            case DOKU_LEXER_UNMATCHED:

                $tables = $this->parseTable($match);
                [$table, $info] = $tables;

                $this->infoTable = $info;

                return array($state, $tables);

            case DOKU_LEXER_EXIT:
                return array($state, '');
            case DOKU_LEXER_SPECIAL:
                break;
        }
        return array();
    }


    /**
     * Create output
     */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode == 'xhtml') {

            if (empty($data[1])) {
                return;
            }

            list($state, $tables) = $data;

            [$match, $info] = $tables;
            $this->infoTable = $info;

            switch ($state) {
                case DOKU_LEXER_ENTER:
                    //$renderer->doc .= "<div class='avMathTable'>";
                    break;

                case DOKU_LEXER_MATCHED:
                    break;

                case DOKU_LEXER_UNMATCHED:
                    $info = [];

                    $output = $this->renderArrayIntoTable($match);
                    $html = p_render('xhtml', p_get_instructions($output), $info);

                    $renderer->doc .= "<div class='avmathtable'>" . $html . "</div>";

                    break;

                case DOKU_LEXER_EXIT:
                    //$renderer->doc .= "</div>";
                    break;

                case DOKU_LEXER_SPECIAL:
                    break;
            }
            return true;
        }
        return false;
    }

    /**
     * Parse the table syntax into an array.
     */
    private function parseTable(string $tableSyntax): array
    {
        // Parse the wiki table text into a collection of instructions.
        $calls = p_get_instructions($tableSyntax);

        // Convert to a multidimensional array
        $table = [];
        $row   = [];
        $cell  = null;

        $infoTable = [];    // Keep track of things like if it's a header cell or a regular cell, alignment, etc.
        $infoRow   = [];
        $infoCell  = ['type' => 'plain', 'alignment' => 'left'];

        foreach ($calls as $call) {
            [$cmd, $data] = $call;

            switch ($cmd) {

                case 'tableheader_open':
                    $cell = '';
                    $infoCell  = ['type' => 'header', 'alignment' => (is_null($data[1]) ? 'left' : $data[1])];

                    break;
                case 'tablecell_open':
                    $cell = '';
                    $infoCell  = ['type' => 'plain', 'alignment' => (is_null($data[1]) ? 'left' : $data[1])];
                    break;

                case 'cdata':
                    if ($cell !== null) {
                        $cell .= $data[0];
                    }
                    break;

                case 'tableheader_close':
                case 'tablecell_close':
                    $row[] = trim($cell);
                    $infoRow[] = $infoCell;
                    $cell  = null;  // Reset
                    $infoCell  = ['type' => 'plain', 'alignment' => 'left']; // Reset
                    break;

                case 'tablerow_close':
                    $table[] = $row;
                    $infoTable[] = $infoRow;
                    $row = [];
                    $infoRow = [];
                    break;
            }
        }

        return [$table, $infoTable];
    }


    private function renderArrayIntoTable(array $table): string
    {
        $output = '';

        $columnData = [];
        $rowData = [];
        $rowNum = 1;

        // Create each row:
        foreach ($table as $i => $row) {
            $colNum = 1; // First column of a new row.
            // Create each cell:
            foreach ($row as $j => $cell) {

                // Initialize info about this column.
                if ($rowNum == 1) {
                    $columnData[$j] = [
                        'sum' => 0,
                        'count' => 0,
                        'total' => 0,
                        'precision' => 0,
                        'min' => null,
                        'max' => null
                    ];
                }

                // Initialize info about this row.
                if ($colNum == 1) {
                    $rowData[$i] = [
                        'sum' => 0,
                        'count' => 0,
                        'precision' => 0,
                        'min' => null,
                        'max' => null
                    ];
                }

                // Open up the cell wiki syntax.
                if ($this->infoTable[$i][$j]['type'] == 'header') {
                    $output .= "^ ";
                } else {
                    $output .= "| ";
                }
                if ($this->infoTable[$i][$j]['alignment'] == 'right' || $this->infoTable[$i][$j]['alignment'] == 'center') {
                    $output .= " ";
                }

                // Gather info about the numbers in this cell.
                if (is_numeric($cell)) {
                    $columnData[$j]['count'] += 1;
                    $rowData[$i]['count'] += 1;
                    $columnData[$j]['precision'] = max($columnData[$j]['precision'], $this->countDecimalPlaces($cell));
                    $rowData[$i]['precision'] = max($rowData[$i]['precision'], $this->countDecimalPlaces($cell));
                    if ((int)$cell == $cell) {
                        $numericCell = intval($cell);
                    } elseif ((float)$cell == $cell) {
                        $numericCell = floatval($cell);
                    }
                    $columnData[$j]['sum'] += $numericCell;
                    $rowData[$i]['sum'] += $numericCell;
                    $columnData[$j]['total'] += $numericCell;
                    $columnData[$j]['max'] = is_null($columnData[$j]['max']) ? $numericCell : max($columnData[$j]['max'], $numericCell);
                    $columnData[$j]['min'] = is_null($columnData[$j]['min']) ? $numericCell : min($columnData[$j]['min'], $numericCell);
                    $rowData[$i]['max'] = is_null($rowData[$i]['max']) ? $numericCell : max($rowData[$i]['max'], $numericCell);
                    $rowData[$i]['min'] = is_null($rowData[$i]['min']) ? $numericCell : min($rowData[$i]['min'], $numericCell);
                }

                // Insert the cell value. TODO : Handle special math features.
                $output .= $this->insertCellData($cell, $columnData, $rowData, $j, $i);


                // Close up the cell wiki syntax.
                if ($this->infoTable[$i][$j]['alignment'] == 'left' || $this->infoTable[$i][$j]['alignment'] == 'center') {
                    $output .= " ";
                }
                if ($this->infoTable[$i][$j]['type'] == 'header') {
                    $output .= " ^";
                } else {
                    $output .= " |";
                }
                $colNum++;
            }
            $output .= "\n"; // End of a row.
            $rowNum++;
        }

        //$dump = var_export($table, true);

        return $output;
    }

    /**
     * Put the value back in the cell. Substitute math where applicable.
     */
    private function insertCellData(mixed $cell, array &$columnData, array &$rowData, int $colNum, int $rowNum,)
    {

        // echo('<pre>');
        // var_dump($columnData);
        // echo('</pre>');

        switch (trim($cell)) {
            case '=SUM':
                return '<span class="avmathtablevalue">' . number_format($columnData[$colNum]['sum'], $columnData[$colNum]['precision']) .'</span>';
                break;
            case '=ROW':
                return '<span class="avmathtablevalue">' . number_format($rowData[$rowNum]['sum'], $rowData[$rowNum]['precision']) .'</span>';
                break;
            case '=TOT':
                $temp = number_format($columnData[$colNum]['total'], $columnData[$colNum]['precision']);
                $columnData[$colNum]['total'] = 0; // Reset to begin a new total.
                return '<span class="avmathtablevalue">' . $temp .'</span>';
                break;
            case '=CNT':
                return '<span class="avmathtablevalue">' . $columnData[$colNum]['count'] . '</span>';
                break;
            case '=AVG':
                return '<span class="avmathtablevalue">' . number_format(round(($columnData[$colNum]['sum'] / $columnData[$colNum]['count']), $columnData[$colNum]['precision']+1), $columnData[$colNum]['precision']+1) . '</span>';
                break;
            case '=MAX':
                return '<span class="avmathtablevalue">' . $columnData[$colNum]['max'] . '</span>';
                break;
            case '=MIN':
                return '<span class="avmathtablevalue">' . $columnData[$colNum]['min'] . '</span>';
                break;
            default:
                return $cell;
        }
    }

    /**
     * Count the decimal places after the period.
     * Note that 50.00 gets treated as 50, so we need to count zeros separately and take the largest number.
     */
    private function countDecimalPlaces(mixed $num): int
    {
        // Number of 0s after the decimal:
        $numZeros = 0;
        if (strpos($num, '.') !== false) {
            preg_match("/^(0+)/", explode('.', $num)[1], $matches);
            $numZeros = strlen($matches[0]);
        }

        // Count number of significant digits after the decimal:
        $fNumber = floatval($num);
        for ($iDecimals = 0; $fNumber != round($fNumber, $iDecimals); $iDecimals++);
        return max($iDecimals, $numZeros);
    }
} // End class
