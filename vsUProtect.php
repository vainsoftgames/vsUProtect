<?php

	class vsUProtect {
		public $tmp_dir = '/tmp/';
		function __construct(string $consoleIP, string $api_key) {
			$this->ip = $consoleIP;
			$this->api_key = $api_key;
		}

		private function callAPI(string $endpoint, array|bool $payload=false, string $method='GET', bool $isRaw=false){
			$headers = [];
			$headers[] = 'X-API-KEY: '. $this->api_key;
			$headers[] = 'Accept: application/json';

			$url = ('https://'. $this->ip .'/proxy/protect/integration/'. $endpoint);

			// If it's a GET and we were given a payload, treat it as query params.
			if ($payload !== false && strtoupper($method) === 'GET') {
				$qs = http_build_query($payload);
				if ($qs !== '') $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
			}

			$ch = curl_init($url);
			// Default request type
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			
			// If we have a payload, encode and attach it
			if ($payload !== false && in_array(strtoupper($method), ['POST','PUT','PATCH'])) {
				$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
				$headers[] = 'Content-Type: application/json; charset=utf-8';
				curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			}

			// Add custom headers
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			// Return response instead of outputting it
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			// Disable SSL verification (ignore cert validation)
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

			$response = curl_exec($ch);

			if (curl_errno($ch)) {
				$response = [
					'status'	=> 'error',
					'msg'		=> curl_error($ch)
				];
			}
			else if($isRaw) $response = ($response);
			else $response = json_decode($response, true);

			curl_close($ch);

			return $response;
		}

		/****
		 Console Functions
		 ****/
		public function getMeta(){
			return $this->callAPI('v1/meta/info');
		}

		/****
		 Camera Functions
		 ****/
		public function getCams() : array {
			return $this->callAPI('v1/cameras');
		}
		public function getCam(string $camID) : array {
			return $this->callAPI('v1/cameras/'. $camID);
		}

		// Stream management
		private function cleanRTSP(string $str) : string {
			if(empty($str)) return $str;
			return str_replace(['rtsps:', 7441, '?enableSrtp'], ['rtsp:', 7447, ''], $str);
		}

		public function getCamStreams(string $camID, bool $secure=true) : array {
			$result = $this->callAPI('v1/cameras/'. $camID .'/rtsps-stream');
			if(!$secure && is_arraY($result)){
				foreach($result as $k=>$v){
					if(!empty($v)){
						$result[$k] = $this->cleanRTSP($v);
					}
				}
			}
			return $result;
		}

		public function createCamStream(string $camID, string|array $quality='low', bool $secure=true) : array {
			if(is_string($quality)) $quality = [$quality];
			$result = $this->callAPI('v1/cameras/'. $camID .'/rtsps-stream', ['qualities'=>$quality], 'POST');

			if(!$secure && is_arraY($result)){
				foreach($result as $k=>$v){
					if(!empty($v)){
						$result[$k] = $this->cleanRTSP($v);
					}
				}
			}

			return $result;
		}

		public function getCamSnapshot(string $camID, bool $highQuality=false, int $fitToWidth=0) : string {
			$result = $this->callAPI('v1/cameras/'. $camID .'/snapshot', ['forceHighQuality'=>$highQuality], 'GET', true);

			if($fitToWidth <= 0) return $result;

			// Create an image from the bytes (handles JPEG/PNG/WebP, etc.)
			$src = @imagecreatefromstring($result);
			if (!$src) {
				// If GD can't parse it for some reason, just return original
				return $bytes;
			}

			$srcW = imagesx($src);
			$srcH = imagesy($src);

			// Don’t upscale
			if ($fitToWidth >= $srcW) {
				imagedestroy($src);
				return $bytes;
			}

			// Compute target size (preserve aspect ratio)
			$targetW = $fitToWidth;
			$targetH = (int) round($srcH * ($targetW / $srcW));

			// Resample
			$dst = imagecreatetruecolor($targetW, $targetH);
			imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);

			// Encode JPEG to memory (quality 85 is a nice default)
			ob_start();
			imagejpeg($dst, null, 85);
			$out = ob_get_clean();

			imagedestroy($dst);
			imagedestroy($src);

			return $out;
		}

		public function createPreview(string $camID, bool $highQuality=false, int $fitToWidth=0, float $fps=1, int $dur=5, string $format='mp4', bool $audio=false, string|bool $saveTo=false) : string|bool {
			$format = strtolower($format);
			if (!in_array($format, ['mp4','gif'], true)) {
				throw new \InvalidArgumentException("format must be 'mp4' or 'gif'");
			}
			if ($fps < 1) $fps = 1;
			if ($dur < 1) $dur = 1;

			// Ensure ffmpeg is available (cross-platform)
			$exit = 0; $out = [];
			@exec('ffmpeg -version', $out, $exit);
			if ($exit !== 0) {
				throw new \RuntimeException("ffmpeg not found on PATH; install ffmpeg to enable previews.");
			}

			// Decide capture method
			$method = 'still';
			$streamURL = null;

			// Prefer stream if available (order by quality based on $highQuality)
			$streams = $this->getCamStreams($camID, false);
			if ($streams && is_array($streams)) {
				$order = $highQuality ? ['high','medium','low'] : ['low','medium','high'];
				foreach ($order as $key) {
					if (!empty($streams[$key]) && stripos($streams[$key], 'rtsp') !== false) {
						$streamURL = $streams[$key];
						$method = 'stream';
						break;
					}
				}
			}

			// Temp base always needed (output lives here if saveTo is false)
			$base = rtrim($this->tmp_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uprotect_' . bin2hex(random_bytes(6));
			if (!@mkdir($base, 0770, true)) {
				throw new \RuntimeException("Failed to create temp directory: $base");
			}


			try {
				$outputPath = $base . DIRECTORY_SEPARATOR . ($format === 'mp4' ? 'preview.mp4' : 'preview.gif');
				$targetPath = $saveTo ?: $outputPath;

				// Ensure saveTo dir exists if provided
				if ($saveTo) {
					$dir = dirname($targetPath);
					if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
						throw new \RuntimeException("Cannot create directory: $dir");
					}
				}

				// Build ffmpeg command differently for stream vs stills
				$cmd = '';

				if ($method === 'stream') {
					// STREAM: sample FPS and optional scale in filtergraph
					$filters = ["fps={$fps}"];
					if ($fitToWidth > 0) $filters[] = "scale={$fitToWidth}:-2:flags=lanczos";
					$vf = ' -vf ' . escapeshellarg(implode(',', $filters));

					$escapedIn  = escapeshellarg($streamURL);
					$escapedOut = escapeshellarg($targetPath);

					$cmd = "ffmpeg -y -hide_banner -loglevel error -rtsp_transport tcp -stimeout 5000000 -t " . (int)$dur ." -i $escapedIn";
					if ($format === 'mp4') {
						$cmd .= "$vf -c:v libx264 -pix_fmt yuv420p -movflags +faststart -crf 23 -preset veryfast";
						if(!$audio) $cmd .= " -an";
						$cmd .= " $escapedOut";
					}
					// GIF
					else {
						// GIF: split->palettegen/paletteuse + optional scale included above
						$filter = implode(',', $filters);
						$fc = "split[s0][s1];[s0]palettegen=stats_mode=full[p];[s1][p]paletteuse=dither=sierra2_4a";
						// Insert fps/scale before split if present
						if (!empty($filter)) $fc = $filter . ',' . $fc;
						$cmd .= "-filter_complex " . escapeshellarg($fc) . " " . escapeshellarg($targetPath);
					}
				}
				else {
					// STILLS: capture frames then encode (you already resized in getCamSnapshot)
					$framesDir = $base . DIRECTORY_SEPARATOR . 'frames';
					if (!@mkdir($framesDir, 0770, true)) {
						throw new \RuntimeException("Failed to create temp frames directory");
					}

					$totalFrames = $fps * $dur;
					$intervalUs = (int)floor(1_000_000 / $fps);
					for ($i = 0; $i < $totalFrames; $i++) {
						$bytes = $this->getCamSnapshot($camID, $highQuality, $fitToWidth);
						$framePath = $framesDir . DIRECTORY_SEPARATOR . sprintf('frame_%05d.jpg', $i);
						if (@file_put_contents($framePath, $bytes) === false) {
							throw new \RuntimeException("Failed to write frame $i");
						}
						if ($i + 1 < $totalFrames) usleep($intervalUs);
					}

					$escapedFrames = escapeshellarg($framesDir . DIRECTORY_SEPARATOR . 'frame_%05d.jpg');
					$escapedOut    = escapeshellarg($targetPath);

					$cmd = "ffmpeg -y -hide_banner -loglevel error -framerate " . (int)$fps ." -i $escapedFrames";
					if ($format === 'mp4') {
						$cmd .= " -c:v libx264 -pix_fmt yuv420p -movflags +faststart -crf 23 -preset veryfast $escapedOut";
					}
					// GIF
					else {
						$cmd .= " -filter_complex " .
							   escapeshellarg("split[s0][s1];[s0]palettegen=stats_mode=full[p];[s1][p]paletteuse=dither=sierra2_4a") .
							   " $escapedOut";
					}
				}

				$out = []; $exit = 0;
				@exec($cmd . " 2>&1", $out, $exit);
				if ($exit !== 0 || !is_file($targetPath)) {
					$err = implode("\n", $out);
					throw new \RuntimeException("ffmpeg failed: " . $err);
				}

				if ($saveTo) return true;

				$bytes = file_get_contents($targetPath);
				if ($bytes === false) {
					throw new \RuntimeException("Failed to read output file");
				}
				return $bytes;

			}
			finally {
				$this->cleanupDir($base);
			}
		}

		// n/a
		public function createTalkback(string $camID){
			return $this->callAPI('v1/cameras/'. $camID .'/talkback-session');
		}
		/**
		 * Recursively remove a directory (best-effort).
		 */
		private function cleanupDir(string $path) : void {
			if (!is_dir($path)) return;
			$it = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
			$files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($files as $file) {
				$file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
			}
			@rmdir($path);
		}



		/****
		 Viewer Functions
		 ****/
		public function getViewers(){
			return $this->callAPI('v1/viewers');
		}


		public function getLiveViews(){
			return $this->callAPI('v1/liveviews');
		}


		/****
		 NVR Functions
		 ****/
		public function getNVRDetails(){
			return $this->callAPI('v1/nvrs');
		}


		/****
		 Chime Functions
		 ****/
		public function getChimes(){
			return $this->callAPI('v1/chimes');
		}
	}
?>
