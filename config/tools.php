<?php

/**
 * Specialized generation-tool presets (AITUBER mission §6B). Each tool is a
 * PRESET over the ONE shared generation pipeline (story → scenes → images →
 * voices → clips → render): it prefills the visual style, presentation
 * (audio workflow) mode and a story-brief scaffold. No tool runs a
 * different engine, and no tool advertises a capability the pipeline does
 * not have — limitations are stated in the description text.
 */

return [

    'tools' => [
        'idea-to-video' => [
            'label' => 'Idea to Video',
            'description' => 'Type a short idea — the AI writes the story, plans the scenes, generates cartoon images, voices and renders the video.',
            'category' => 'story',
            'visual_style' => 'simple_2d_cartoon',
            'presentation_mode' => 'narrator',
            'brief_scaffold' => 'A short story about: ',
        ],
        'script-to-video' => [
            'label' => 'Script to Video',
            'description' => 'Paste a finished script and the pipeline turns it into a planned, voiced, rendered video.',
            'category' => 'story',
            'visual_style' => 'animated_2d_cartoon',
            'presentation_mode' => 'dialogue',
            'brief_scaffold' => '',
        ],
        'ai-clips' => [
            'label' => 'AI Clips',
            'description' => 'Generate short AI-animated clips per scene (image-to-video), then assemble them with voices and captions.',
            'category' => 'video',
            'visual_style' => 'anime',
            'presentation_mode' => 'dialogue',
            'brief_scaffold' => 'A fast-paced scene sequence about: ',
        ],
        'avatar-talking-head' => [
            'label' => 'Avatar Talking Head',
            'description' => 'A recurring character speaks to the camera. Uses an audio-input lip-sync video model when the generation mode is animated; TTS drives the voice.',
            'category' => 'character',
            'visual_style' => 'animated_2d_cartoon',
            'presentation_mode' => 'dialogue',
            'brief_scaffold' => 'A friendly host presents and talks directly to the audience about: ',
        ],
        '3d-characters-talking' => [
            'label' => '3D Characters Talking',
            'description' => 'Stylized 3D-look characters in dialogue. Lip sync uses the configured animated-mode model (audio-input or native-speech).',
            'category' => 'character',
            'visual_style' => 'claymation',
            'presentation_mode' => 'dialogue',
            'brief_scaffold' => 'Two stylized characters with depth and volume discuss: ',
        ],
        'ai-music-video' => [
            'label' => 'AI Music Video',
            'description' => 'Animated visuals synced to your uploaded, licence-verified music track. AI music generation is disabled (licensing) — upload your own audio.',
            'category' => 'audio',
            'visual_style' => 'papercut',
            'presentation_mode' => 'narrator',
            'brief_scaffold' => 'An energetic visual sequence that matches the mood of the uploaded music: ',
        ],
        'medical-animation' => [
            'label' => 'Medical Animation',
            'description' => 'Clean explainer visuals for health topics. Presentations of medical information must be reviewed for accuracy before publishing.',
            'category' => 'explainer',
            'visual_style' => 'simple_2d_cartoon',
            'presentation_mode' => 'narrator',
            'brief_scaffold' => 'A clear, calm explainer of the health topic: ',
        ],
        'skeleton-video' => [
            'label' => 'Skeleton Video',
            'description' => 'Bone-and-joint anatomy explainers with a consistent skeletal character cast.',
            'category' => 'explainer',
            'visual_style' => 'comic_book',
            'presentation_mode' => 'narrator',
            'brief_scaffold' => 'A fun anatomy lesson where a skeleton character teaches: ',
        ],
        'construction-timelapse' => [
            'label' => 'Construction Timelapse',
            'description' => 'A build-progress story told through animated scenes of a construction project, from groundbreaking to finish.',
            'category' => 'explainer',
            'visual_style' => 'papercut',
            'presentation_mode' => 'narrator',
            'brief_scaffold' => 'A construction project grows from start to finish: ',
        ],
        'ugc-demo-videos' => [
            'label' => 'UGC Demo Videos',
            'description' => 'User-generated-style product demos with a casual presenter character. Vertical 9:16 output fits Shorts/Reels/TikTok.',
            'category' => 'ugc',
            'visual_style' => 'pixel_art',
            'presentation_mode' => 'dialogue',
            'brief_scaffold' => 'A casual presenter demos a product and shares their honest experience with: ',
        ],
        'ugc-reaction-videos' => [
            'label' => 'UGC Reaction Videos',
            'description' => 'Reaction-style commentary with expressive presenter characters reacting to a topic or product.',
            'category' => 'ugc',
            'visual_style' => 'comic_book',
            'presentation_mode' => 'dialogue',
            'brief_scaffold' => 'A presenter reacts with big emotions to: ',
        ],
        'kids-storytime' => [
            'label' => 'Kids Storytime',
            'description' => 'Bedtime-style children’s stories with youthful-leaning voices and gentle visuals.',
            'category' => 'story',
            'visual_style' => 'storybook',
            'presentation_mode' => 'narrator',
            'brief_scaffold' => 'A gentle bedtime story for children about: ',
        ],
    ],

];
