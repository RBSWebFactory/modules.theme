<?php
/**
 * @package modules.theme
 * @method theme_ModuleService getInstance()
 */
class theme_ModuleService extends ModuleBaseService
{
	/**
	 * @param integer $documentId
	 * @return theme_persistentdocument_pagetemplate[]
	 */
	public function getAllowedTemplateForDocumentId($documentId)
	{
		$result = array();
		try 
		{
			$document = DocumentHelper::getDocumentInstance($documentId);
			$ancestors = $document->getDocumentService()->getAncestorsOf($document);
			$ancestors[] = $document;
			foreach (array_reverse($ancestors) as $ancestor) 
			{
				if ($ancestor instanceof website_persistentdocument_website || 
					$ancestor instanceof website_persistentdocument_topic) 
				{
					$result = $ancestor->getPublishedAllowedpagetemplateArray();
					if (count($result))
					{
						break;
					}
				}
			}	
		}
		catch (Exception $e)
		{
			Framework::exception($e);
		}
		return $result;
	}
	
	public function initPaths()
	{
		$paths = array(
			f_util_FileUtils::buildProjectPath('themes'),
			f_util_FileUtils::buildProjectPath('media', 'themes')
		);
		foreach ($paths as $path) 
		{
			f_util_FileUtils::mkdir($path);
		}		
	}
	
	public function removeThemePaths($codeName)
	{
		f_util_FileUtils::rmdir(f_util_FileUtils::buildProjectPath('themes', $codeName));
		f_util_FileUtils::rmdir(f_util_FileUtils::buildProjectPath('media', 'themes', $codeName));		
	}
	
	/**
	 * @param string $codeName
	 */
	public function initThemePaths($codeName)
	{
		$paths = array(
			f_util_FileUtils::buildProjectPath('themes', $codeName, 'templates'),
			f_util_FileUtils::buildProjectPath('themes', $codeName, 'style'),
			f_util_FileUtils::buildProjectPath('themes', $codeName, 'js'),
			f_util_FileUtils::buildProjectPath('themes', $codeName, 'locale'),
			f_util_FileUtils::buildProjectPath('themes', $codeName, 'image'),
			f_util_FileUtils::buildProjectPath('media', 'themes', $codeName)
		);
		foreach ($paths as $path) 
		{
			f_util_FileUtils::mkdir($path);
		}		
	}

	/**
	 * @param string $codeName
	 * @param generic_persistentdocument_folder $folder
	 * @return theme_persistentdocument_theme
	 */
	public function installTheme($codeName, $folder = null)
	{
		$script = change_FileResolver::getNewInstance()->getPath('themes', $codeName, 'setup', 'init.xml');		
		if (!file_exists($script))
		{
			throw new Exception('Invalid theme: ' .$codeName);
		}
		$theme = $this->regenerateTheme($codeName, $folder);
		
		$scriptReader = import_ScriptReader::getInstance();
		$scriptReader->execute($script);

		return $theme;
	}
	
	/**
	 * @param boolean $doEcho
	 */
	public function regenerateAllThemes($doEcho = false)
	{
		$path = f_util_FileUtils::buildProjectPath('themes', '*');
		$themes = glob($path, GLOB_ONLYDIR);
		if (is_array($themes))
		{
			foreach ($themes as $codeName)
			{
				$this->regenerateTheme(basename($codeName), null, $doEcho);
			}
		}
	}
	
	/**
	 * @param string $codeName
	 * @param generic_persistentdocument_folder $folder
	 * @param boolean $doEcho
	 * @return theme_persistentdocument_theme
	 */
	public function regenerateTheme($codeName, $folder = null, $doEcho = false)
	{
		if ($doEcho)
		{
			echo "Compile theme: $codeName\n";
		}
		$theme = theme_ThemeService::getInstance()->refreshByFiles($codeName, $folder);
		if (!$theme)
		{
			Framework::warn(__METHOD__ . ' Unable to regenerate Theme: '. $codeName);
			return null;
		}
		theme_ImageService::getInstance()->refreshByFiles($theme);
		theme_JavascriptService::getInstance()->refreshByFiles($theme);
		theme_CssService::getInstance()->refreshByFiles($theme);
		theme_PagetemplateService::getInstance()->refreshByFiles($theme);
		
		$theme->save();
		LocaleService::getInstance()->regenerateLocalesForTheme('themes_' . $codeName);
		
		$this->buildSkinVars($theme, $doEcho);
		
		return $theme;
	}
	
	/**
	 * @param theme_persistentdocument_theme $theme
	 * @param boolean $doEcho
	 */
	private function buildSkinVars($theme, $doEcho = false)
	{
		$skinVarsPath = change_FileResolver::getNewInstance()->getPath('themes', $theme->getCodename(), 'skin', 'skin.xml');
		$skinVars = array();	
		if ($skinVarsPath)
		{
			$skinDoc = f_util_DOMUtils::fromPath($skinVarsPath);
			$fields = $skinDoc->find('//field[@name]');
			foreach ($fields as $field) 
			{
				$varName = $field->getAttribute('name');
				$skinVars[$varName] = array('type' => $field->getAttribute('type'), 'ini' => $field->getAttribute('initialvalue'));
			}
		}
		$variablesPath = f_util_FileUtils::buildChangeBuildPath('themes', $theme->getCodename(), 'variables.ser');
		if ($doEcho)
		{
			echo "Update: $variablesPath\n";
		}
		f_util_FileUtils::writeAndCreateContainer($variablesPath, serialize($skinVars), f_util_FileUtils::OVERRIDE);
	}
	
	/**
	 * @param string $codeName
	 * @return DOMDocument
	 */
	public function getEditVariablesBinding($codeName)
	{
		$theme = theme_ThemeService::getInstance()->getByCodeName($codeName);
		if (!$theme)
		{
			throw new Exception('Theme not found: ' . $codeName);
		}
		
		theme_BindingHelper::setCurrentTheme($theme);
		
		$xslPath = change_FileResolver::getNewInstance()->getPath('modules', 'theme', 'templates', 'variables.xsl');
		$skinDefPath = change_FileResolver::getNewInstance()->getPath('themes', $codeName, 'skin', 'skin.xml');

		$skinDefDoc = new DOMDocument('1.0', 'UTF-8');
		$skinDefDoc->load($skinDefPath);
			
		$xsl = new DOMDocument('1.0', 'UTF-8');
		$xsl->load($xslPath);
		$xslt = new XSLTProcessor();
		$xslt->registerPHPFunctions();
		$xslt->importStylesheet($xsl);
		$xslt->setParameter('', 'theme', $codeName);
		$panelDoc = $xslt->transformToDoc($skinDefDoc);
		return $panelDoc;
	}
	
	/**
	 * @return string[]
	 */
	public function getDeadPageStatuses()
	{
		return array(
			f_persistentdocument_PersistentDocument::STATUS_DEPRECATED,
			f_persistentdocument_PersistentDocument::STATUS_FILED,
			f_persistentdocument_PersistentDocument::STATUS_TRASH,
		);
	}
}