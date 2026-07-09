<?php
/** @joinery-test
 * name: email_template_iteration
 * tier: safe            # pure reflection over EmailTemplate; no DB, no mail
 * env: any
 * needs: []
 */
// Phase 1 of receipts_refactor.md: verify {loop X as Y}...{end} iteration
// in EmailTemplate. Exercises the engine directly via reflection so the test
// doesn't depend on database fixtures or a working email pipeline.
//
// Usage: php tests/email/template_iteration_test.php

require_once(__DIR__ . '/../lib/harness.php');
require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
harness_boot();

class EmailTemplateIterationTest {
    private $instance;
    private $renderString;
    private $expandLoops;
    private $substituteVariables;

    public function __construct() {
        $reflection = new ReflectionClass('EmailTemplate');
        $this->instance = $reflection->newInstanceWithoutConstructor();

        $this->renderString = $reflection->getMethod('_render_string');
        $this->renderString->setAccessible(true);

        $this->expandLoops = $reflection->getMethod('_expand_loops');
        $this->expandLoops->setAccessible(true);

        $this->substituteVariables = $reflection->getMethod('_substitute_variables');
        $this->substituteVariables->setAccessible(true);
    }

    private function render($template, $values) {
        return $this->renderString->invokeArgs($this->instance, [$template, $values]);
    }

    private function expandLoops($template, $values) {
        return $this->expandLoops->invokeArgs($this->instance, [$template, $values]);
    }

    private function assertEquals($name, $expected, $actual) {
        check($expected === $actual, $name,
            $expected === $actual ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public function run() {
        section('EmailTemplate {loop X as Y} iteration');

        $this->testNoOpForTemplatesWithoutLoops();
        $this->testSimpleLoop();
        $this->testLoopOverObjectProperties();
        $this->testNestedLoop();
        $this->testLoopWithConditionalInside();
        $this->testConditionalWrappingLoop();
        $this->testEmptyArray();
        $this->testMissingKey();
        $this->testNonArrayValue();
        $this->testLoopLocalVariableReference();
        $this->testMultiLevelObjectResolution();
        $this->testMultipleLoopsSequential();
    }

    // --- Test cases -----------------------------------------------------

    private function testNoOpForTemplatesWithoutLoops() {
        echo "test: no-op for templates without loops\n";

        // expand_loops should return identity for any template without "{loop ".
        $samples = [
            "Plain text with no markup at all.",
            "<p>Hello *name*</p>",
            "{cond}\nshown\n{end}\nplain",
            "{a == b}match{end}",
            "Some {regular} text with literal braces but no loop directive.",
        ];
        foreach ($samples as $idx => $template) {
            $out = $this->expandLoops($template, ['name' => 'World']);
            $this->assertEquals("identity #$idx", $template, $out);
        }
    }

    private function testSimpleLoop() {
        echo "test: simple loop\n";

        $template = "{loop items as item}- *item*\n{end}";
        $values = ['items' => ['apple', 'banana', 'cherry']];
        $out = $this->render($template, $values);
        $this->assertEquals('renders three lines', "- apple\n- banana\n- cherry\n", $out);
    }

    private function testLoopOverObjectProperties() {
        echo "test: loop over array of dicts\n";

        $template = "{loop people as person}*person->name* (*person->age*)\n{end}";
        $values = [
            'people' => [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ],
        ];
        $out = $this->render($template, $values);
        $this->assertEquals('per-element property access', "Alice (30)\nBob (25)\n", $out);
    }

    private function testNestedLoop() {
        echo "test: nested loops\n";

        $template = "{loop groups as group}*group->name*:{loop group->members as m}*m*,{end}\n{end}";
        $values = [
            'groups' => [
                ['name' => 'A', 'members' => ['x', 'y']],
                ['name' => 'B', 'members' => ['z']],
            ],
        ];
        $out = $this->render($template, $values);
        $this->assertEquals('inner loop binds group->members', "A:x,y,\nB:z,\n", $out);
    }

    private function testLoopWithConditionalInside() {
        echo "test: conditional inside loop\n";

        $template = "{loop items as item}{item->show}YES *item->name*{end}{~item->show}NO{end}\n{end}";
        $values = [
            'items' => [
                ['name' => 'a', 'show' => 1],
                ['name' => 'b', 'show' => 0],
                ['name' => 'c', 'show' => 1],
            ],
        ];
        $out = $this->render($template, $values);
        $this->assertEquals('per-iteration conditional', "YES a\nNO\nYES c\n", $out);
    }

    private function testConditionalWrappingLoop() {
        echo "test: conditional wrapping a loop\n";

        $template = "{show_list}List:\n{loop items as item}- *item*\n{end}{end}";
        $valuesShown = ['show_list' => 1, 'items' => ['x', 'y']];
        $valuesHidden = ['show_list' => 0, 'items' => ['x', 'y']];
        $this->assertEquals('renders when conditional is true',
            "List:\n- x\n- y\n",
            $this->render($template, $valuesShown));
        $this->assertEquals('skips when conditional is false',
            "",
            $this->render($template, $valuesHidden));
    }

    private function testEmptyArray() {
        echo "test: empty array\n";

        $template = "before\n{loop items as item}- *item*\n{end}after";
        $values = ['items' => []];
        $out = $this->render($template, $values);
        $this->assertEquals('renders before/after, no iteration', "before\nafter", $out);
    }

    private function testMissingKey() {
        echo "test: missing array key\n";

        $template = "before\n{loop nothing as item}- *item*\n{end}after";
        $values = []; // no 'nothing' key
        $out = $this->render($template, $values);
        $this->assertEquals('lenient: renders empty body', "before\nafter", $out);
    }

    private function testNonArrayValue() {
        echo "test: non-array value\n";

        $template = "{loop count as item}*item*{end}";
        $values = ['count' => 42]; // scalar, not an array
        $out = $this->render($template, $values);
        $this->assertEquals('lenient: renders empty body', "", $out);
    }

    private function testLoopLocalVariableReference() {
        echo "test: loop-local variable accessible inside body\n";

        $template = "{loop xs as x}*x*={x}TRUTHY{end}{~x}FALSY{end} {end}";
        $values = ['xs' => [1, 0, 'hello']];
        $out = $this->render($template, $values);
        $this->assertEquals('loop-local x reaches conditional', "1=TRUTHY 0=FALSY hello=TRUTHY ", $out);
    }

    private function testMultiLevelObjectResolution() {
        echo "test: multi-level item->a->b resolution inside loop\n";

        $template = "{loop rows as row}*row->user->email*\n{end}";
        $values = [
            'rows' => [
                ['user' => ['email' => 'a@example.com']],
                ['user' => ['email' => 'b@example.com']],
            ],
        ];
        $out = $this->render($template, $values);
        $this->assertEquals('three-level path resolves',
            "a@example.com\nb@example.com\n", $out);
    }

    private function testMultipleLoopsSequential() {
        echo "test: two loops in sequence\n";

        $template = "A:{loop a as x}*x*{end} B:{loop b as y}*y*{end}";
        $values = ['a' => [1, 2], 'b' => ['x', 'y', 'z']];
        $out = $this->render($template, $values);
        $this->assertEquals('both loops expand', "A:12 B:xyz", $out);
    }
}

$test = new EmailTemplateIterationTest();
$test->run();
harness_finish();
