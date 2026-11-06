<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

/**
 * The product's own words inside the data tools' descriptions (plan mploynow-mira-parity P0d).
 *
 * ⚠ THE EXAMPLES TEACH THE MODEL WHAT TO SEARCH FOR. Portify's say "LMIA" and "biometrics" because a
 * consultant asks about those; MployNow's say "shortlisted" and "offer sent". A kit-wide example list would
 * be right for nobody, and a Portify list served to MployNow would send Mira looking for cases.
 *
 * Each string is spliced into a sentence exactly as given (see {@see DataTools::definitions()}).
 */
final class DataToolWords
{
    /**
     * @param  string  $records  the kinds of records a person owns, e.g. "cases, clients, payments, documents, meetings"
     * @param  string  $searchTerms  quoted words worth searching, e.g. '"active", "waiting", "LMIA", "biometrics"'
     * @param  string  $namedThings  what find_record finds, e.g. "person, business or case"
     * @param  string  $names  quoted example names, e.g. '"Maria Garcia", "Acme Ltd"'
     * @param  string  $valueTerms  quoted real-world filter words, e.g. '"LMIA", "approved"'
     * @param  string  $naming  how NOT to name a record to a person, e.g. "never a bare case number"
     * @param  \Closure(list<string>): string|null  $recordTypesNote  extra guidance given the "key (plural)" labels
     *                                                               this audience can query; '' for none
     */
    public function __construct(
        public readonly string $records = 'people, records, payments, documents, meetings',
        public readonly string $searchTerms = '"active", "waiting", "approved"',
        public readonly string $namedThings = 'person, business or record',
        public readonly string $names = '"Maria Garcia", "Acme Ltd"',
        public readonly string $valueTerms = '"approved"',
        public readonly string $naming = 'never a bare id or reference number',
        public readonly ?\Closure $recordTypesNote = null,
    ) {}

    /** @param  list<string>  $labels */
    public function noteFor(array $labels): string
    {
        return $this->recordTypesNote === null ? '' : (string) ($this->recordTypesNote)($labels);
    }
}
