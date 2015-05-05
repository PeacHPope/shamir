<?php

namespace TQ\Shamir\Algorithm;


use TQ\Shamir\Random\Generator;
use TQ\Shamir\Random\PhpGenerator;

/**
 * Class Shamir
 *
 * Based on "Shamir's Secret Sharing class" from Kenny Millington
 *
 * @link    http://www.kennynet.co.uk/misc/shamir.class.txt
 *
 * @package TQ\Shamir\Algorithm
 */
class Shamir implements Algorithm, RandomGeneratorAware
{
    /**
     * Calculation base (decimal)
     *
     * @const string
     */
    const DECIMAL = '0123456789';

    /**
     * Target base characters to be used in passwords (shares)
     *
     * @const string
     */
    const CHARS = '0123456789abcdefghijklmnopqrstuvwxyz.,:;!?*#%';

    /**
     * Character to fill up the secret keys
     *
     * @const string
     */
    const PAD_CHAR = '=';

    /**
     * Prime number has to be greater than 256
     *
     * @var int
     */
    protected $prime = 4294967311;

    /**
     * Part size in bytes
     *
     * The secret will be divided in different by sizes.
     * This value defines how many bytes will get encoded
     * at once.
     *
     * @var int
     */
    protected $partSize = 1;

    /**
     * The random generator
     *
     * @var Generator
     */
    protected $randomGenerator;

    /**
     * Cache of the inverse table
     *
     * @var array
     */
    protected $invTab;


    /**
     * @inheritdoc
     */
    public function setRandomGenerator(Generator $generator)
    {
        $this->randomGenerator = $generator;
    }

    /**
     * @inheritdoc
     */
    public function getRandomGenerator()
    {
        if (!$this->randomGenerator) {
            $this->randomGenerator = new PhpGenerator();
        }

        return $this->randomGenerator;
    }

    /**
     * Calculate modulo of any given number using prime
     *
     * @param   integer     Number
     * @return  integer     Module of number
     */
    protected function modulo($number)
    {
        $modulo = $number % $this->prime;

        return ($modulo < 0) ? $modulo + $this->prime : $modulo;
    }

    /**
     * Calculates the a lookup table for reverse coefficients
     *
     * @return array
     */
    protected function invTab()
    {
        if (!isset($this->invTab)) {
            $x = $y = 1;
            $this->invTab = array(0 => 0);
            for ($i = 0; $i < $this->prime; $i++) {
                $this->invTab[$x] = $y;
                $x = $this->modulo(3 * $x);
                $y = $this->modulo(86 * $y);
            }
        }
//        print_r($this->invTab);

        return $this->invTab;
    }

    /**
     * Calculates the inverse modulo
     *
     * @param int $i
     * @return int
     */
    protected function inv($i)
    {
        $invTab = $this->invTab();

        return ($i < 0) ? $this->modulo(-$invTab[-$i]) : $invTab[$i];
    }

    /**
     * Calculates the reverse coefficients
     *
     * @param   array $keyX
     * @param   int $threshold
     * @return  array
     * @throws  \RuntimeException
     */
    protected function reverseCoefficients(array $keyX, $threshold)
    {
        $coefficients = array();

        for ($i = 0; $i < $threshold; $i++) {
            $temp = 1;
            for ($j = 0; $j < $threshold; $j++) {
                if ($i != $j) {
                    $temp = $this->modulo(
                        -$temp * $keyX[$j] * $this->inv($keyX[$i] - $keyX[$j])
                    );
                }
            }

            if ($temp == 0) {
                /* Repeated share */
                throw new \RuntimeException('Repeated share detected - cannot compute reverse-coefficients');
            }

            $coefficients[] = $temp;
        }

        return $coefficients;
    }

    /**
     * Generate random coefficient
     *
     * @param   integer $threshold Number of coefficients needed
     * @return  array                   Random coefficients
     */
    protected function generateCoefficients($threshold)
    {
        $coefficients = array();
        for ($i = 0; $i < $threshold - 1; $i++) {
            do {
                // the random number has to be positive integer != 0
                $random = abs($this->getRandomGenerator()->getRandomInt());
            } while ($random < 1);
            $coefficients[] = $this->modulo($random);
        }

        return $coefficients;
    }

    /**
     * Calculate y values of polynomial curve using horner's method
     *
     * Horner converts a polynomial formula like
     * 11 + 7x - 5x^2 - 4x^3 + 2x^4
     * into a more efficient formula
     * 11 + x * ( 7 + x * ( -5 + x * ( -4 + x * 2 ) ) )
     *
     * @see     http://en.wikipedia.org/wiki/Horner%27s_method
     * @param   integer $x X coordinate
     * @param   array $coefficients Polynomial coefficients
     * @return  integer                     Y coordinate
     */
    protected function hornerMethod($x, array $coefficients)
    {
        $y = 0;
        foreach ($coefficients as $c) {
            $y = $this->modulo($x * $y + $c);
        }

        return $y;
    }

    /**
     * Converts from $fromBaseInput to $toBaseInput
     *
     * @param   string $numberInput
     * @param   string $fromBaseInput
     * @param   string $toBaseInput
     * @return  string
     */
    protected static function convBase($numberInput, $fromBaseInput, $toBaseInput)
    {
        if ($fromBaseInput == $toBaseInput) {
            return $numberInput;
        }
        $fromBase = str_split($fromBaseInput, 1);
        $toBase = str_split($toBaseInput, 1);
        $number = str_split($numberInput, 1);
        $fromLen = strlen($fromBaseInput);
        $toLen = strlen($toBaseInput);
        $numberLen = strlen($numberInput);
        $retVal = '';
        if ($toBaseInput == '0123456789') {
            $retVal = 0;
            for ($i = 1; $i <= $numberLen; $i++) {
                $retVal = bcadd(
                    $retVal,
                    bcmul(array_search($number[$i - 1], $fromBase), bcpow($fromLen, $numberLen - $i))
                );
            }

            return $retVal;
        }
        if ($fromBaseInput != '0123456789') {
            $base10 = self::convBase($numberInput, $fromBaseInput, '0123456789');
        } else {
            $base10 = $numberInput;
        }
        if ($base10 < strlen($toBaseInput)) {
            return $toBase[$base10];
        }
        while ($base10 != '0') {
            $retVal = $toBase[bcmod($base10, $toLen)] . $retVal;
            $base10 = bcdiv($base10, $toLen, 0);
        }

        return $retVal;
    }


    /**
     * Configure encoding parameters
     *
     * Depending on the number of required keys, we need to change
     * prime number, key length and more
     *
     * @param   int $max Maximum number of keys needed
     * @throws  \OutOfRangeException
     */
    protected function setMaxShares($max)
    {
        // the prime number has to be larger, than the maximum number
        // representable by the number of bytes. so we always need one
        // byte more for encryption. if someone wants to use 256 shares,
        // we could encrypt 256 with a single byte, but due to encrypting
        // with a bigger prime number, we will need to use 2 bytes.

        // max possible number of shares is the maximum number of bytes
        // possible to be represented with max integer, but we always need
        // to save one byte for encryption.
        $maxPossible = 1 << (PHP_INT_SIZE - 1) * 8;

        if ($max > $maxPossible) {
            // we are unable to provide more bytes-1 as supported by OS
            // because the prime number need to be higher than that, but
            // this would exceed OS int range.
            throw new \OutOfRangeException(
                'Number of required keys has to be below ' . number_format($maxPossible) . '.'
            );
        }

        // calculate how many bytes we need to represent number of shares.
        // e.g. everything less than 256 needs only a single byte.
        $bytes = ceil(log($max, 2) / 8);
        switch ($bytes) {
            case 1:
                // 256
                $prime = 257;
                break;
            case 2:
                // 2 bytes: 65536
                $prime = 65537;
                break;
            case 3:
                // 3 bytes: 16777216
                $prime = 1677727;
                break;
            case 4:
                // 4 bytes: 4294967296
                $prime = 4294967311;
                break;
            case 5:
                // 5 bytes: 1099511627776
                $prime = 1099511627791;
                break;
            case 6:
                // 6 bytes: 281474976710656
                $prime = 281474976710677;
                break;
            case 7:
                // 7 bytes: 72057594037927936
                $prime = 72057594037928017;
                break;
            default:
                throw new \OutOfRangeException('Prime with that many bytes are not implemented yet.');
        }

        $this->partSize = $bytes;
        $this->prime = $prime;
    }


    /**
     * Unpack a binary string and convert it into decimals
     *
     * Convert each chunk of a binary data into decimal numbers.
     *
     * @param   string $string Binary string
     * @return  array           Array with decimal converted numbers
     */
    protected function unpack($string)
    {
        $part = 0;
        $int = null;
        $return = array();
        foreach (unpack('C*', $string) as $byte) {
            $int += $byte * pow(2, $part * 8);
            if (++$part == $this->partSize) {
                $return[] = $int;
                $part = 0;
                $int = null;
            }
        }
        if ($part != 0) {
            $return[] = $int;
        }

        return $return;
    }


    /**
     * Returns maximum length of converted string to new base
     *
     * Calculate the maximum length of a string, which can be
     * represented with the number of given bytes and convert
     * its base.
     *
     * @param   integer $bytes Bytes used to represent a string
     * @return  integer Number of chars
     */
    protected function maxKeyLength($bytes)
    {
        $maxInt = pow(2, $bytes * 8);
        $converted = self::convBase($maxInt, self::DECIMAL, self::CHARS);
        return strlen($converted);
    }


    /**
     * @inheritdoc
     */
    public function share($secret, $shares, $threshold = 2)
    {
        $this->setMaxShares($shares);

        // check if number of shares is less than our prime, otherwise we have a security problem
        if ($shares >= $this->prime || $shares < 1) {
            throw new \OutOfRangeException('Number of shares has to be between 0 and ' . $this->prime . '.');
        }

        if ($shares < $threshold) {
            throw new \OutOfRangeException('Threshold has to be between 0 and ' . $threshold . '.');
        }

        if (strpos(self::CHARS, self::PAD_CHAR) !== false) {
            throw new \OutOfRangeException('Padding character must not be part of possible encryption chars.');
        }

        // divide secret into single bytes, which we encrypt one by one
        $result = array();
        foreach ($this->unpack($secret) as $bytes) {
            $coeffs = $this->generateCoefficients($threshold);
            $coeffs[] = $bytes;

            // go through x coordinates and calculate y value
            for ($x = 1; $x <= $shares; $x++) {
                // use horner method to calculate y value
                $result[] = $this->hornerMethod($x, $coeffs);
            }
        }
        unset($coeffs);


        // encode number of bytes and threshold

        // calculate the maximum length of key sequence number and threshold
        $maxBaseLength = $this->maxKeyLength($this->partSize);
        // in order to do a correct padding to the converted base, we need to use the first char of the base
        $paddingChar = substr(self::CHARS, 0, 1);
        // define prefix number using the number of bytes (hex), and a left padded string used for threshold (base converted)
        $fixPrefixFormat = '%x%' . $paddingChar . $maxBaseLength . 's';
        // prefix is going to be the same for all keys
        $prefix = sprintf($fixPrefixFormat, $this->partSize, self::convBase($threshold, self::DECIMAL, self::CHARS));

        // convert y coordinates into hexadecimals shares
        $passwords = array();
        $secretLen = strlen($secret);
        $padding = $secretLen % $this->partSize;

        for ($i = 0; $i < $shares; ++$i) {
            $sequence = self::convBase(($i + 1), self::DECIMAL, self::CHARS);
            $key = sprintf($prefix . '%' . $paddingChar . $maxBaseLength . 's', $sequence);

            for ($j = 0; $j < $secretLen; $j += $this->partSize) {
                $x = ceil($j / $this->partSize);

                if ($j + $this->partSize <= $secretLen) {
                    $key .= str_pad(
                        self::convBase($result[$x * $shares + $i], self::DECIMAL, self::CHARS),
                        $maxBaseLength,
                        $paddingChar,
                        STR_PAD_LEFT
                    );
                } else {
                    // add padding to end of string, so we can strip it off while recovering the password
                    // this is needed, because otherwise we would have NULL bytes at the end.
                    $key .= str_pad(
                        self::convBase($result[$x * $shares + $i], self::DECIMAL, self::CHARS),
                        $maxBaseLength - $padding,    // reduce length by padding
                        $paddingChar,
                        STR_PAD_LEFT
                    );
                    $key .= str_repeat(self::PAD_CHAR, $padding);
                }

            }
            $passwords[] = $key;
        }

        return $passwords;
    }

    /**
     * @inheritdoc
     */
    public function recover(array $keys)
    {
        if (!count($keys)) {
            throw new \RuntimeException('No keys given.');
        }

        $keyX = array();
        $keyY = array();
        $keyLen = null;
        $threshold = null;

        foreach ($keys as $key) {
            $key = str_replace(self::PAD_CHAR, '', $key);

            // extract "public" information of key: bytes, threshold, sequence

            // first we need to find out the bytes to predict threshold and sequence length
            $bytes = hexdec(substr($key, 0, 1));
            // calculate the maximum length of key sequence number and threshold
            $maxBaseLength = $this->maxKeyLength($bytes);

            // define key format: bytes (hex), threshold, sequence, and key (except of bytes, all is base converted)
            $keyFormat = '%1x%' . $maxBaseLength . 's%' . $maxBaseLength . 's%s';
            list($bytes, $minimum, $sequence, $key) = sscanf($key, $keyFormat);
            $minimum = self::convBase($minimum, self::CHARS, self::DECIMAL);
            $sequence = self::convBase($sequence, self::CHARS, self::DECIMAL);

            if ($threshold === null) {
                $threshold = (int)$minimum;
                $stepSize = $bytes + 1;
            } elseif ($threshold != (int)$minimum) {
                throw new \RuntimeException('Given keys are incompatible.');
            } elseif ($threshold > count($keys)) {
                throw new \RuntimeException('Not enough keys to disclose secret.');
            }

            $keyX[] = (int)$sequence;
            if ($keyLen === null) {
                $keyLen = strlen($key);
            } elseif ($keyLen != strlen($key)) {
                throw new \RuntimeException('Given keys vary in key length.');
            }
            for ($i = 0; $i < strlen($key); $i += $stepSize) {
                $keyY[] = self::convBase(substr($key, $i, $stepSize), self::CHARS, self::DECIMAL);
            }
        }


        $coefficients = $this->reverseCoefficients($keyX, $threshold);
        $keyLen /= $stepSize;
        $secret = '';
        for ($i = 0; $i < $keyLen; $i++) {
            $temp = 0;
            for ($j = 0; $j < $threshold; $j++) {

                $temp = $this->modulo(
                    $temp + $keyY[$keyLen * $j + $i] * $coefficients[$j]
                );
            }
            // convert each byte back into char
            for ($byte = 1; $byte <= $bytes; ++$byte) {
                $char = $temp % 256;
                $secret .= chr($char);
                $temp = ($temp - $char) / 256;
            }
        }

        // remove padding from secret (NULL bytes);
        $padCount = substr_count(reset($keys), '=');

        return substr($secret, 0, -1 * $padCount);
    }


}