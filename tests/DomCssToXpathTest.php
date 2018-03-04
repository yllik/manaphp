<?php
namespace Tests;

use ManaPHP\Dom\CssToXpath;

class DomCssToXpathTest extends \PHPUnit_Framework_TestCase
{
    public function test_transform()
    {
        $cssToXpath = new CssToXpath();
        $css_xpaths = [

            //all selector
            '*' => '//*',

            //element types
            'div' => '//div',
            'a' => '//a',
            'span' => '//span',
            'h2' => '//h2',

            //style attributes
            '.error' => "//*[contains(concat(' ', normalize-space(@class), ' '), ' error ')]",
            'div.error' => "//div[contains(concat(' ', normalize-space(@class), ' '), ' error ')]",
            'label.required' => "//label[contains(concat(' ', normalize-space(@class), ' '), ' required ')]",

            //id attributes
            '#content' => "//*[@id='content']",
            'div#nav' => "//div[@id='nav']",

            //arbitrary attributes
            'div[bar="baz"]' => "//div[@bar='baz']",//exact match
            'div[bar~="baz"]' => "//div[contains(concat(' ', normalize-space(@bar), ' '), ' baz ')]",//word match
            'div[bar*="baz"]' => "//div[contains(@bar, 'baz')]",//substring match

            //has attributes
        //    'div[bar]' => '//div[@bar]',//has bar attributes

            //direct descendents
            'div > span' => '//div/span',

            //descendents
            'div .foo span #one' => "//div//*[contains(concat(' ', normalize-space(@class), ' '), ' foo ')]//span//*[@id='one']|//div[contains(concat(' ', normalize-space(@class), ' '), ' foo ')]//span//*[@id='one']",

            'foo|bar' => '//foo|bar',

            //https://stackoverflow.com/questions/1604471/how-can-i-find-an-element-by-css-class-with-xpath
            'div>.foo' => "//div/[contains(concat(' ', normalize-space(@class), ' '), ' foo ')]",
            'div > .foo' => "//div/[contains(concat(' ', normalize-space(@class), ' '), ' foo ')]",
            'div>.foo.bar' => "//div/[contains(concat(' ', normalize-space(@class), ' '), ' foo ')][contains(concat(' ', normalize-space(@class), ' '), ' bar ')]",
            'div.bar span' => "//div[contains(concat(' ', normalize-space(@class), ' '), ' bar ')]//span",
            'div > p.first' => "//div/p[contains(concat(' ', normalize-space(@class), ' '), ' first ')]",
            'a[rel="include"]' => "//a[@rel='include']",
            "a[rel='include']" => "//a[@rel='include']",

        ];

        foreach ($css_xpaths as $css => $xpath) {
            $this->assertEquals($xpath, $cssToXpath->transform($css), json_encode(['css'=>$css,'xpath'=>$xpath], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        }
    }
}