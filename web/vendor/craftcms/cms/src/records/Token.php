<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\records;

use craft\db\ActiveRecord;
use craft\db\Table;
use craft\validators\DateTimeValidator;

/**
 * Token record.
 *
 * @property int $id ID
 * @property string $token Token
 * @property array $route Route
 * @property int|null $usageLimit Usage limit
 * @property int|null $usageCount Usage count
 * @property string|null $expiryDate Expiry date
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class Token extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['usageLimit', 'usageCount'], 'number', 'min' => 0, 'max' => 255, 'integerOnly' => true],
            [['expiryDate'], DateTimeValidator::class],
            [['token'], 'unique'],
            [['token', 'expiryDate'], 'required'],
            [['token'], 'string', 'length' => 32],
        ];
    }

    /**
     * @inheritdoc
     * @return string
     */
    public static function tableName(): string
    {
        return Table::TOKENS;
    }
}
