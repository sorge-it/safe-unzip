<?php

declare(strict_types=1);

namespace SorgeIt\SafeUnzip\Profile;

use SorgeIt\SafeUnzip\Archive\Archive;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Limits;
use SorgeIt\SafeUnzip\Profile\Rule\CharactersRule;
use SorgeIt\SafeUnzip\Profile\Rule\DuplicateRule;
use SorgeIt\SafeUnzip\Profile\Rule\EndRecordRule;
use SorgeIt\SafeUnzip\Profile\Rule\EntryCountRule;
use SorgeIt\SafeUnzip\Profile\Rule\EntryTypeRule;
use SorgeIt\SafeUnzip\Profile\Rule\ExtraFieldRule;
use SorgeIt\SafeUnzip\Profile\Rule\FileAndDirectoryRule;
use SorgeIt\SafeUnzip\Profile\Rule\FlagsRule;
use SorgeIt\SafeUnzip\Profile\Rule\HeaderMismatchRule;
use SorgeIt\SafeUnzip\Profile\Rule\HostRule;
use SorgeIt\SafeUnzip\Profile\Rule\LayoutRule;
use SorgeIt\SafeUnzip\Profile\Rule\LimitRule;
use SorgeIt\SafeUnzip\Profile\Rule\MethodRule;
use SorgeIt\SafeUnzip\Profile\Rule\MultiDiskRule;
use SorgeIt\SafeUnzip\Profile\Rule\OuterBytesRule;
use SorgeIt\SafeUnzip\Profile\Rule\PartLengthRule;
use SorgeIt\SafeUnzip\Profile\Rule\TraversalRule;
use SorgeIt\SafeUnzip\Profile\Rule\TypeMismatchRule;
use SorgeIt\SafeUnzip\Profile\Rule\UnicodeFieldRule;
use SorgeIt\SafeUnzip\Profile\Rule\VersionRule;
use SorgeIt\SafeUnzip\Profile\Rule\Zip64Rule;

/**
 * The allow list "standard ZIP": each rule in turn, the first one that breaks throws. The order
 * puts the cause before its effects, such as bytes before the archive before the headers they move.
 *
 * @internal
 */
final readonly class Profile
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct(Limits $limits)
    {
        $this->rules = [
            new MultiDiskRule,
            new Zip64Rule,
            new OuterBytesRule,
            new EndRecordRule,
            new EntryCountRule,
            new FlagsRule,
            new LayoutRule,
            // Before each rule on names: the zipinfo of Debian and Alpine lists the name of a
            // Unicode path field, the one of macOS the name field.
            new UnicodeFieldRule,
            new TraversalRule,
            new CharactersRule,
            new PartLengthRule,
            new HeaderMismatchRule,
            new MethodRule,
            new VersionRule,
            new HostRule,
            new ExtraFieldRule,
            new EntryTypeRule,
            new TypeMismatchRule,
            new DuplicateRule,
            new FileAndDirectoryRule,
            new LimitRule($limits),
        ];
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function check(Archive $archive): void
    {
        foreach ($this->rules as $rule) {
            $detail = $rule->check($archive);

            if ($detail !== null) {
                throw new RefusedException($rule->reason(), $detail);
            }
        }

        // A warning or an error that no rule explains: an archive the profile does not know.
        $listing = $archive->listing;

        if ($listing->exit !== 0 && ! ($listing->exit === 1 && $listing->entries === [] && trim($listing->errors) === '')) {
            throw new InvalidArchiveException(sprintf('zipinfo stopped with exit code %d: %s Nothing was unpacked.', $listing->exit, trim($listing->errors)));
        }
    }
}
