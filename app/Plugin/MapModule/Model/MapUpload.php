<?php
// Copyright (C) <2015>  <it-novum GmbH>
//
// This file is dual licensed
//
// 1.
//	This program is free software: you can redistribute it and/or modify
//	it under the terms of the GNU General Public License as published by
//	the Free Software Foundation, version 3 of the License.
//
//	This program is distributed in the hope that it will be useful,
//	but WITHOUT ANY WARRANTY; without even the implied warranty of
//	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//	GNU General Public License for more details.
//
//	You should have received a copy of the GNU General Public License
//	along with this program.  If not, see <http://www.gnu.org/licenses/>.
//

// 2.
//	If you purchased an openITCOCKPIT Enterprise Edition you can use this file
//	under the terms of the openITCOCKPIT Enterprise Edition license agreement.
//	License agreement and license key will be shipped with the order
//	confirmation.


class MapUpload extends MapModuleAppModel {

    const TYPE_BACKGROUND = 1;
    const TYPE_ICON_SET = 2;

    public $belongsTo = [
        'Container' => [
            'foreignKey' => 'container_id',
            'className'  => 'Container',
        ],
        'User'      => [
            'foreignKey' => 'user_id',
            'className'  => 'User',
        ]
    ];

    public $supportedFileExtensions = ['jpg', 'gif', 'png', 'jpeg'];

    public function getIconsNames() {
        return [
            'ack.png',
            'critical.png',
            'down.png',
            'error.png',
            'okaytime.png',
            'okaytimeuser.png',
            'ok.png',
            'pending.png',
            'sack.png',
            'sdowntime.png',
            'unknown.png',
            'unreachable.png',
            'up.png',
            'warning.png',
        ];
    }

    /**
     * @param $imageConfig
     * @param Folder $Folder
     * @throws Exception
     */
    public function createThumbnailsFromBackgrounds($imageConfig, Folder $Folder) {

        $file = $imageConfig['fullPath'];

        //check if thumb folder exist
        if (!is_dir($Folder->path . DS . 'thumb')) {
            mkdir($Folder->path . DS . 'thumb');
        }

        $imgsize = getimagesize($file);
        $width = $imgsize[0];
        $height = $imgsize[1];
        $imgtype = $imgsize[2];
        $aspectRatio = $width / $height;

        $thumbnailWidth = 150;
        $thumbnailHeight = 150;


        switch ($imgtype) {
            /**
             * 1 => GIF
             * 2 => JPG
             * 3 => PNG
             * 4 => SWF
             * 5 => PSD
             * 6 => BMP
             * 7 => TIFF(intel byte order)
             * 8 => TIFF(motorola byte order)
             * 9 => JPC
             * 10 => JP2
             * 11 => JPX
             * 12 => JB2
             * 13 => SWC
             * 14 => IFF
             * 15 => WBMP
             * 16 => XBM
             */
            case 1:
                $srcImg = imagecreatefromgif($file);
                break;
            case 2:
                $srcImg = imagecreatefromjpeg($file);
                break;
            case 3:
                $srcImg = imagecreatefrompng($file);
                break;
            default:
                throw new Exception('Filetype not supported!');
                break;
        }

        //calculate the new height or width and keep the aspect ration
        if ($aspectRatio == 1) {
            //source image X = Y
            $newWidth = $thumbnailWidth;
            $newHeight = $thumbnailHeight;
        } else if ($aspectRatio > 1) {
            //source image X > Y
            $newWidth = $thumbnailWidth;
            $newHeight = ($thumbnailHeight / $aspectRatio);
        } else {
            //source image X < Y
            $newWidth = ($thumbnailWidth * $aspectRatio);
            $newHeight = $thumbnailHeight;
        }

        $destImg = imagecreatetruecolor($newWidth, $newHeight);
        $transparent = imagecolorallocatealpha($destImg, 0, 0, 0, 127);
        imagefill($destImg, 0, 0, $transparent);
        imageCopyResized($destImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagealphablending($destImg, false);
        imagesavealpha($destImg, true);


        //Save image to disk
        switch ($imgtype) {
            /**
             * 1 => GIF
             * 2 => JPG
             * 3 => PNG
             * 4 => SWF
             * 5 => PSD
             * 6 => BMP
             * 7 => TIFF(intel byte order)
             * 8 => TIFF(motorola byte order)
             * 9 => JPC
             * 10 => JP2
             * 11 => JPX
             * 12 => JB2
             * 13 => SWC
             * 14 => IFF
             * 15 => WBMP
             * 16 => XBM
             */
            case 1:
                imagegif($destImg, $Folder->path . DS . 'thumb' . DS . 'thumb_' . $imageConfig['uuidFilename'] . '.' . $imageConfig['fileExtension']);
                break;
            case 2:
                imagejpeg($destImg, $Folder->path . DS . 'thumb' . DS . 'thumb_' . $imageConfig['uuidFilename'] . '.' . $imageConfig['fileExtension']);
                break;
            case 3:
                imagepng($destImg, $Folder->path . DS . 'thumb' . DS . 'thumb_' . $imageConfig['uuidFilename'] . '.' . $imageConfig['fileExtension']);
                break;
            default:
                throw new Exception('Filetype not supported!');
                break;
        }
        imagedestroy($destImg);
    }

    /**
     * @param $fileExtension
     * @return bool
     */
    public function isFileExtensionSupported($fileExtension) {
        return in_array(strtolower(trim($fileExtension)), $this->supportedFileExtensions, true);
    }

}
