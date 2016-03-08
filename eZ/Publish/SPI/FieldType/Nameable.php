<?php

/**
 * File containing the FieldType Indexable interface.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @since 6.3/5.4.6
 */
namespace eZ\Publish\SPI\FieldType;

use eZ\Publish\SPI\Persistence\Content\Type\FieldDefinition;

/**
 * The field type interface which all field types have to implement to be
 * able to generate content name when field is part of name-schema or url-schema.
 *
 * For use by business logic (API implementation), so can *not* directly or indirectly rely on API.
 *
 * Most FieldTypes will only need to use Value object in order to generate name, however others will
 * for instance need to use other services. Psudo-example for a Content Relation FieldType:
 * ```php
 * class NameField implements Nameable
 * {
 *     public function __construct(ContentHandler $contentHandler){...}
 *
 *     publish function getName(Value $value, FieldDefinition $fieldDefinition, $languageCode)
 *     {
 *         // This will only return main language but gives an example of use
 *         return $this->contentHandler->loadContentInfo($value->destinationContentId)->name;
 *     }
 * }
 * ```
 */
interface Nameable
{
    /**
     * Returns a human readable string representation from the given $value.
     *
     * It will be used to generate content name and url alias if current field
     * is designated to be used in the content name/urlAlias pattern.
     *
     * The used $value can be assumed to be already accepted by {@link FieldType::acceptValue()}.
     *
     * @param \eZ\Publish\SPI\FieldType\Value $value
     * @param \eZ\Publish\SPI\Persistence\Content\Type\FieldDefinition $fieldDefinition
     * @param string $languageCode
     *
     * @return string
     */
    public function getName(Value $value, FieldDefinition $fieldDefinition, $languageCode);
}
