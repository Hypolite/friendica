<?php
/**
 * @file src/Render/TemplateEngine.php
 */
namespace Friendica\Render;

require_once 'boot.php';

/**
 * Interface for template engines
 */
interface ITemplateEngine
{
	public function replaceMacros($s, $v);
	public function getTemplateFile($file, $root = '');
}
