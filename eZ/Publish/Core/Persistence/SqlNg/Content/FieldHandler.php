<?php

namespace eZ\Publish\Core\Persistence\SqlNg\Content;

use eZ\Publish\Core\Persistence\SqlNg\Content\Type\Handler as ContentTypeHandler;
use eZ\Publish\Core\Persistence\SqlNg\Content\Gateway as ContentGateway;

use eZ\Publish\SPI\Persistence\Content;
use eZ\Publish\SPI\Persistence\Content\Type;
use eZ\Publish\SPI\Persistence\Content\Field;

class FieldHandler
{
    /**
     * @var eZ\Publish\Core\Persistence\SqlNg\Content\Type\Handler
     */
    protected $contentTypeHandler;

    /**
     * @var eZ\Publish\Core\Persistence\SqlNg\Language\Handler
     */
    protected $languageHandler;

    /**
     * @var eZ\Publish\Core\Persistence\SqlNg\Content\Gateway
     */
    protected $contentGateway;

    /**
     * @param eZ\Publish\Core\Persistence\SqlNg\Content\Type\Handler $contentTypeHandler
     * @param eZ\Publish\Core\Persistence\SqlNg\Content\Language\Handler $languageHandler
     */
    public function __construct( ContentTypeHandler $contentTypeHandler, Language\Handler $languageHandler, ContentGateway $contentGateway )
    {
        $this->contentTypeHandler = $contentTypeHandler;
        $this->languageHandler = $languageHandler;
        $this->contentGateway = $contentGateway;
    }

    /**
     * @param eZ\Publish\SPI\Persistence\Content\Field $fields
     * @return eZ\Publish\Core\Persistence\SqlNg\Content\StorageField
     */
    public function createStorageFields( array $fields, $contentTypeId )
    {
        $type = $this->contentTypeHandler->load( $contentTypeId );

        $storageFields = array();
        foreach ( $fields as $field )
        {
            $storageFields[] = $this->createStorageField( $type, $field );
        }
        return $storageFields;
    }

    /**
     * @param eZ\Publish\Core\Persistence\SqlNg\Content\StorageField $storageFields
     * @return eZ\Publish\SPI\Persistence\Content\Field
     */
    public function extractFields( array $storageFields )
    {
        $fields = array();
        foreach ( $storageFields as $storageField )
        {
            $fields[] = $storageField->field;
        }
        return $fields;
    }

    /**
     * Updates fields in given $content to fit to an updated version of $contentTypeId
     *
     * @param \eZ\Publish\SPI\Persistence\Content $content
     * @return \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField[]
     */
    public function updateFieldsToNewContentType( Content $content )
    {
        $type = $this->contentTypeHandler->load(
            $content->versionInfo->contentInfo->contentTypeId
        );

        $fieldDefsByIdentifier = $this->getFieldDefinitionsByIdentifier( $type->fieldDefinitions );

        $updated = false;
        foreach ( $content->fields as $id => $storageField )
        {
            $identifier = $storageField->fieldDefinitionIdentifier;

            if ( !isset( $fieldDefsByIdentifier[$identifier] ) )
            {
                unset( $content->fields[$id] );
                $updated = true;
                continue;
            }

            if ( $fieldDefsByIdentifier[$identifier]->id != $storageField->field->fieldDefinitionId )
            {
                $storageField->field->fieldDefinitionId = $fieldDefsByIdentifier[$identifier]->id;
                $updated = true;
            }
        }

        if ( $updated )
        {
            $this->contentGateway->updateFields(
                $content->versionInfo->contentInfo->id,
                $content->versionInfo->versionNo,
                $content->fields
            );
        }
    }

    /**
     * Completes $storageFields for $contentTypeId in $languages
     *
     * @param \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField[] $storageFields
     * @param mixed $contentTypeId
     * @param mixed[] $languageIds
     */
    public function completeFieldsByContentType( array $storageFields, $contentTypeId, array $languageIds )
    {
        $fieldsMap = $this->getFieldsByIdentifierAndLanguage( $storageFields );
        $contentType = $this->contentTypeHandler->load( $contentTypeId );

        $languageHandler = $this->languageHandler;

        $languageCodes = array_map(
            function ( $languageId ) use ( $languageHandler )
            {
                return $languageHandler->load( $languageId )->languageCode;
            },
            $languageIds
        );
        $defaultLanguageCode = $this->languageHandler->load( $contentType->initialLanguageId )->languageCode;

        foreach ( $contentType->fieldDefinitions as $fieldDefinition )
        {
            $identifier = $fieldDefinition->identifier;

            if ( !isset( $fieldsMap[$identifier] ) )
            {
                $fieldsMap[$identifier] = array();
            }

            if ( !isset( $fieldsMap[$identifier][$defaultLanguageCode] ) )
            {
                $fieldsMap[$identifier][$defaultLanguageCode] = clone $fieldDefinition->defaultValue;
            }

            foreach ( $languageCodes as $languageCode )
            {
                if ( !isset( $fieldsMap[$identifier][$languageCode] ) )
                {
                    $fieldsMap[$identifier][$languageCode] = clone $fieldsMap[$identifier][$defaultLanguageCode];
                }
            }
        }

        return $this->flattenFieldsMap( $fieldsMap );
    }

    /**
     * Removes all redundant fields from $storageFields
     *
     * This includes:
     * - Fields that have their default value
     * - Fields that are not translatable, but occur in multiple languages
     *
     * @param \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField[] $storageFields
     * @param mixed $contentTypeId
     * @return \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField[]
     */
    public function removeRedundantFieldValues( array $storageFields, $contentTypeId )
    {
        $type = $this->contentTypeHandler->load( $contentTypeId );

        $fieldDefsByIdentifier = $this->getFieldDefinitionsByIdentifier( $type->fieldDefinitions );
        $defaultLanguageCode = $this->languageHandler->load( $type->initialLanguageId )->languageCode;

        $fieldMap = $this->getFieldsByIdentifierAndLanguage( $storageFields );

        foreach ( $fieldMap as $defIdentifier => $languageFields )
        {
            $fieldDefinition = $fieldDefsByIdentifier[$defIdentifier];

            $missingDefaultLanguage = false;
            if ( !$fieldDefinition->isTranslatable && !isset( $languageFields[$defaultLanguageCode] ) )
            {
                $missingDefaultLanguage = true;
            }

            foreach ( $languageFields as $languageCode => $storageField )
            {
                if ( $storageField->field->value == $fieldDefinition->defaultValue )
                {
                    unset( $fieldMap[$defIdentifier][$languageCode] );
                    continue;
                }

                if ( $missingDefaultLanguage )
                {
                    $fieldMap[$defIdentifier][$defaultLanguageCode] = clone $storageField;
                    $missingDefaultLanguage = false;
                }

                if ( !$fieldDefinition->isTranslatable && $storageField->field->languageCode !== $defaultLanguageCode )
                {
                    unset( $fieldMap[$defIdentifier][$languageCode] );
                    continue;
                }
            }
        }

        return $this->flattenFieldsMap( $fieldMap );
    }

    /**
     * Returns the $storageFields indexed by field definition identifier and language
     *
     * @param \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField[] $storageFields
     * @return \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField[][]
     */
    protected function getFieldsByIdentifierAndLanguage( array $storageFields )
    {
        $fieldsMap = array();

        foreach ( $storageFields as $storageField )
        {
            if ( !isset( $fieldsMap[$storageField->fieldDefinitionIdentifier] ) )
            {
                $fieldsMap[$storageField->fieldDefinitionIdentifier] = array();
            }
            $fieldsMap[$storageField->fieldDefinitionIdentifier][$storageField->field->languageCode] = $storageField;
        }

        return $fieldsMap;
    }

    /**
     * Merges the $fieldsMap back to a flatt array
     *
     * @param \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField[][] $fieldsMap
     * @return \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField[]
     */
    protected function flattenFieldsMap( array $fieldsMap )
    {
        $flattenedFields = array();

        foreach ( $fieldsMap as $languageFields )
        {
            foreach ( $languageFields as $fields )
            {
                $flattenedFields[] = $fields;
            }
        }

        return $flattenedFields;
    }

    /**
     * @param \eZ\Publish\SPI\Persistence\Content\Type $type
     * @param \eZ\Publish\SPI\Persistence\Content\Field $field
     * @return \eZ\Publish\Core\Persistence\SqlNg\Content\StorageField
     */
    protected function createStorageField( Type $type, Field $field )
    {
        return new StorageField(
            array(
                'field' => $field,
                'fieldDefinitionIdentifier' => $this->getFieldDefinitionIdentifier( $type, $field->fieldDefinitionId ),
            )
        );
    }

    /**
     * @param \eZ\Publish\SPI\Persistence\Content\Type $contentType
     * @param mixed $fieldDefinitionId
     */
    protected function getFieldDefinitionIdentifier( Type $contentType, $fieldDefinitionId )
    {
        foreach ( $contentType->fieldDefinitions as $fieldDefinition )
        {
            if ( $fieldDefinitionId == $fieldDefinition->id )
            {
                return $fieldDefinition->identifier;
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Could not find identifier for field definition "%s" in content type "%s".',
                $fieldDefinitionId,
                $contentType->id
            )
        );
    }

    /**
     * Returns field definitions indexed by their identifier
     *
     * @param \eZ\Publish\SPI\Persistence\Content\Type\FieldDefinition[] $fieldDefinitions
     * @return \eZ\Publish\SPI\Persistence\Content\Type\FieldDefinition[string]
     */
    protected function getFieldDefinitionsByIdentifier( array $fieldDefinitions )
    {
        $fieldDefinitionMap = array();

        foreach ( $fieldDefinitions as $fieldDefinition )
        {
            $fieldDefinitionMap[$fieldDefinition->identifier] = $fieldDefinition;
        }

        return $fieldDefinitionMap;
    }
}
