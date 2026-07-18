<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Shopping;

use InvalidArgumentException;

/** Durable shopping memory is limited to non-sensitive product-selection facts. */
final class ShoppingMemoryPrivacyPolicy
{
    /** @var array<string,bool> */
    private const ALLOWED_KEYS = array(
        'budget' => true,
        'min_price' => true,
        'max_price' => true,
        'price_tier' => true,
        'brand' => true,
        'category' => true,
        'product_type' => true,
        'color' => true,
        'size' => true,
        'material' => true,
        'flavor' => true,
        'scent' => true,
        'roast' => true,
        'origin' => true,
        'country_of_origin' => true,
        'weight' => true,
        'volume' => true,
        'quantity' => true,
        'pack_size' => true,
        'unit' => true,
        'model' => true,
        'sku' => true,
        'product_code' => true,
        'compatibility' => true,
        'use_case' => true,
        'feature' => true,
        'stock' => true,
        'rating' => true,
        'sale' => true,
        'sugar' => true,
    );

    /**
     * There is deliberately no wildcard key prefix. A model-proposed suffix is
     * not evidence that a value is a product attribute rather than identity,
     * contact, address, or health data. New product-selection fields must be
     * reviewed and added to this closed list.
     *
     * @return array<int,string>
     */
    public static function allowedConstraintKeys(): array
    {
        return array_keys(self::ALLOWED_KEYS);
    }

    public static function assertConstraintKeyAllowed(string $key): void
    {
        $key = strtolower(trim($key));
        if (isset(self::ALLOWED_KEYS[$key])) {
            return;
        }
        throw new InvalidArgumentException('Shopping memory accepts product-selection fields only.');
    }

    public static function assertConstraintValueAllowed(string $key, string $value): void
    {
        self::assertConstraintKeyAllowed($key);
        self::assertTextAllowed($value);
    }

    public static function assertNarrativeAllowed(string $value): void
    {
        self::assertTextAllowed($value);
    }

    private static function assertTextAllowed(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $normalizedDigits = self::latinDigits($value);
        $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

        $patterns = array(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu',
            '/\b(?:https?:\/\/)?(?:wa\.me|t\.me|telegram|whatsapp|facebook|instagram)\b/iu',
            '/(?:password|passcode|credential|api[ _-]?key|secret|access[ _-]?token|رمز[ _-]?(?:مرور|سري)|كلمة[ _-]?المرور)/iu',
            '/(?:card[ _-]?(?:number|expiry|holder)|cvv|cvc|credit[ _-]?card|بطاقة[ _-]?(?:ائتمان|دفع)|رقم[ _-]?البطاقة)/iu',
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/i',
            '/(?:iban|bank[ _-]?account|routing[ _-]?number|حساب[ _-]?بنكي|آيبان)/iu',
            '/(?:passport|national[ _-]?id|government[ _-]?id|هوية[ _-]?(?:وطنية|شخصية)|جواز[ _-]?السفر)/iu',
            '/(?:medical|health|patient|diagnosis|disease|condition|allergy|medication|diabetes|تشخيص|مرض|حالة[ _-]?صحية|حساسية|دواء|سكري|سجل[ _-]?طبي)/iu',
            '/(?:latitude|longitude|coordinates|إحداثيات|خط[ _-]?العرض|خط[ _-]?الطول)/iu',
            '/(?:address|postal|postcode|zip[ _-]?code|street|avenue|building|apartment|house|district|neighborhood|عنوان|بريد|شارع|جادة|مبنى|عمارة|شقة|منزل|حي)\s*[:：-]?\s*[\p{L}\p{N}]/iu',
            '/(?:my name is|full name|first name|last name|customer name|اسمي|الاسم الكامل|الاسم الأول|اسم العائلة)\s*[:：-]?\s*[\p{L}]/iu',
            '/(?:phone|mobile|telephone|contact|call me|رقم[ _-]?(?:الهاتف|الجوال|التواصل)|هاتف|جوال|اتصل بي)/iu',
            '/@[A-Za-z0-9_\.]{3,}/u',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                throw new InvalidArgumentException('Shopping memory cannot persist sensitive personal data.');
            }
        }

        $digitCount = preg_match_all('/\d/', $normalizedDigits, $matches);
        // A model/sku/product_code key is not proof that a numeric value is a
        // live catalog identifier. Phone-shaped values remain prohibited under
        // every key; alphanumeric catalog codes continue to be accepted.
        if (
            is_int($digitCount) && $digitCount >= 6
            && preg_match('/(?:^|[^\p{L}\p{N}])\+?\d[\d\s().\/-]{4,}\d(?:$|[^\p{L}\p{N}])/u', $normalizedDigits) === 1
        ) {
            throw new InvalidArgumentException('Shopping memory cannot persist sensitive personal data.');
        }
        if (
            is_int($digitCount) && $digitCount >= 13 && $digitCount <= 19
            && preg_match('/(?:\d[\s-]*){13,19}/', $normalizedDigits) === 1
            && self::luhnCandidate($normalizedDigits)
        ) {
            throw new InvalidArgumentException('Shopping memory cannot persist sensitive personal data.');
        }
    }

    private static function latinDigits(string $value): string
    {
        return strtr($value, array(
            '٠' => '0','١' => '1','٢' => '2','٣' => '3','٤' => '4','٥' => '5','٦' => '6','٧' => '7','٨' => '8','٩' => '9',
            '۰' => '0','۱' => '1','۲' => '2','۳' => '3','۴' => '4','۵' => '5','۶' => '6','۷' => '7','۸' => '8','۹' => '9',
        ));
    }

    private static function luhnCandidate(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value);
        if (!is_string($digits) || strlen($digits) < 13 || strlen($digits) > 19) {
            return false;
        }
        $sum = 0;
        $alternate = false;
        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            $number = (int) $digits[$i];
            if ($alternate) {
                $number *= 2;
                if ($number > 9) {
                    $number -= 9;
                }
            }
            $sum += $number;
            $alternate = !$alternate;
        }
        return $sum % 10 === 0;
    }
}
