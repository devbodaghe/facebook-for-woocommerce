<?php
declare(strict_types=1);


class fbUtilsTest extends WP_UnitTestCase {
    public function testRemoveHtmlTags() {
        $string = '<p>Hello World!</p>';
        $expectedOutput = 'Hello World!';
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, true);
        $this->assertEquals($expectedOutput, $actualOutput);
    } 

    public function testKeepHtmlTags() {
        $string = '<p>Hello World!</p>';
        $expectedOutput = '<p>Hello World!</p>';
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, false);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testReplaceSpecialCharacters() {
        $string = 'Hello &amp; World!';
        $expectedOutput = 'Hello & World!';
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, true);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testEmptyString() {
        $string = '';
        $expectedOutput = '';
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, true);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testNullString() {
        $string = null;
        $expectedOutput = null;
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, true);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testNormalizeProductDataDefaultCondition() {
        $data = [
            'title' => 'Test Product'
        ];
        
        $normalized = WC_Facebookcommerce_Utils::normalize_product_data_for_items_batch($data);
        
        $this->assertEquals('new', $normalized['condition']);
    }

    public function testNormalizeProductDataKeepsValidCondition() {
        $validConditions = ['refurbished', 'used', 'new'];
        
        foreach ($validConditions as $condition) {
            $data = [
                'title' => 'Test Product',
                'condition' => $condition
            ];
            
            $normalized = WC_Facebookcommerce_Utils::normalize_product_data_for_items_batch($data);
            
            $this->assertEquals($condition, $normalized['condition']);
        }
    }

    public function testNormalizeProductDataInvalidCondition() {
        $data = [
            'title' => 'Test Product',
            'condition' => 'invalid_condition'
        ];
        
        $normalized = WC_Facebookcommerce_Utils::normalize_product_data_for_items_batch($data);
        
        $this->assertEquals('new', $normalized['condition']);
    }

    public function testNormalizeProductDataCustomAttributes() {
        $data = [
            'title' => 'Test Product',
            'custom_data' => [
                'material' => 'cotton',
                'style' => 'casual'
            ]
        ];
        
        $normalized = WC_Facebookcommerce_Utils::normalize_product_data_for_items_batch($data);
        
        $this->assertArrayNotHasKey('custom_data', $normalized);
        $this->assertArrayHasKey('additional_variant_attribute', $normalized);
        $this->assertEquals('material:cotton,style:casual', $normalized['additional_variant_attribute']);
    }

    public function testNormalizeProductDataAttributesWithCommas() {
        $data = [
            'title' => 'Test Product',
            'custom_data' => [
                'material' => 'cotton, polyester',
                'style' => 'casual, formal'
            ]
        ];
        
        $normalized = WC_Facebookcommerce_Utils::normalize_product_data_for_items_batch($data);
        
        $this->assertEquals('material:cotton polyester,style:casual formal', $normalized['additional_variant_attribute']);
    }

    public function testNormalizeProductDataAttributesWithColons() {
        $data = [
            'title' => 'Test Product',
            'custom_data' => [
                'material:type' => 'cotton:blend',
                'style:category' => 'casual:wear'
            ]
        ];
        
        $normalized = WC_Facebookcommerce_Utils::normalize_product_data_for_items_batch($data);
        
        $this->assertEquals('material type:cotton blend,style category:casual wear', $normalized['additional_variant_attribute']);
    }

    public function testNormalizeProductDataEmptyCustomData() {
        $data = [
            'title' => 'Test Product',
            'custom_data' => []
        ];
        
        $normalized = WC_Facebookcommerce_Utils::normalize_product_data_for_items_batch($data);
        
        $this->assertArrayNotHasKey('custom_data', $normalized);
        $this->assertArrayNotHasKey('additional_variant_attribute', $normalized);
    }
}