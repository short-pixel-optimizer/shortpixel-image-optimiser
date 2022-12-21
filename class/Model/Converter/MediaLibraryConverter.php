<?php
namespace ShortPixel\Model\Converter;
use ShortPixel\Replacer\Replacer as Replacer;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

abstract class MediaLibraryConverter extends Converter
{
	protected $source_url;


	protected function setupReplacer()
	{
		$this->replacer = new Replacer();
		$fs = \wpSPIO()->filesystem();

		$url = $fs->pathToUrl($this->imageModel);

		if ($this->imageModel->isScaled()) // @todo Test these assumptions
		{
			$url = $fs->pathToUrl($this->imageModel->getOriginalFile());
		}

		$this->source_url = $url;
		$this->replacer->setSource($url);

		$this->replacer->setSourceMeta($this->imageModel->getWPMetaData());

	}

	protected function setTarget($newFile)
	{
		$fs = \wpSPIO()->filesystem();

		$url = $fs->pathToUrl($this->imageModel);
		$newUrl = str_replace($this->imageModel->getFileName(), $newFile->getFileName(), $url);

		$this->replacer->setTarget($newUrl);
	}

	protected function updateMetaData($params)
	{
			$newFile = $this->newFile;
			$attach_id = $this->imageModel->get('id');

			$WPMLduplicates = $this->imageModel->getWPMLDuplicates();

			// This action prevents images from being regenerated on the thumbnail hook.
				do_action('shortpixel-thumbnails-before-regenerate', $attach_id );

			// Update attached_file
			$bool = update_attached_file($attach_id, $newFile->getFullPath() );
			if (! $bool)
				return false;

			// Update post mime on attachment
			if (isset($params['success']))
				$post_ar = array('ID' => $attach_id, 'post_mime_type' => 'image/jpeg');
			elseif ( isset($params['restore']) )
				$post_ar = array('ID' => $attach_id, 'post_mime_type' => 'image/png');

			$result = wp_update_post($post_ar);
			if ($result === 0 || is_wp_error($result))
			{
				Log::addError('Issue updating WP Post converter - ' . $attach_id);
				return false;
			}

			$metadata = wp_get_attachment_metadata($attach_id);

			$new_metadata = wp_generate_attachment_metadata($attach_id, $newFile->getFullPath());

			// Metadata might not be array when add_attachment is calling this hook via AdminController ( PNG2JPG)
			if (is_array($metadata))
			{
				// Original Image in the new situation can not be there. Don't preserve it.
				if (isset($metadata['original_image']) && ! isset($new_metadata['original_image']) )
				{
						unset($metadata['original_image']);
				}

				$new_metadata = array_merge($metadata, $new_metadata); // merge to preserve other custom metadata

			}
			Log::addDebug('New Metadata RESULT #' . $attach_id, $new_metadata);
	//		wp_update_post(array('ID' => $attach_id, 'post_mime_type' => 'image/jpeg' ));
			$bool = wp_update_attachment_metadata($attach_id, $new_metadata);


			if (is_array($WPMLduplicates) && count($WPMLduplicates) > 0)
			{
				 foreach ($WPMLduplicates as $duplicate_id)
				 {
						update_attached_file($duplicate_id, $newFile->getFullPath() );
						wp_update_attachment_metadata($duplicate_id, $new_metadata);

						$post_ar["ID"]  = $duplicate_id;
						wp_update_post($post_ar);
				 }
			}

			$this->replacer->setTargetMeta($new_metadata);
			//return $new_metadata;


	}

} // class
