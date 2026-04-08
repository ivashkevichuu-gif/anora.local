import fetch from 'node-fetch';
import { config } from '../config';
import { logger } from '../logger';

const GRAPH_API = 'https://graph.facebook.com/v19.0';

/**
 * Publish a video as an Instagram Reel via Meta Graph API.
 *
 * Flow:
 * 1. POST /{ig-user-id}/media — create media container with video_url
 * 2. Poll container status until FINISHED
 * 3. POST /{ig-user-id}/media_publish — publish the container
 */
export async function publishReel(
  videoUrl: string,
  caption: string
): Promise<{ id: string; success: boolean; error?: string }> {
  const { accessToken, userId } = config.instagram;

  if (!accessToken || !userId) {
    return { id: '', success: false, error: 'Instagram credentials not configured' };
  }

  try {
    // Step 1: Create media container
    logger.info('Creating Instagram media container', { videoUrl });

    const createRes = await fetch(`${GRAPH_API}/${userId}/media`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        media_type: 'REELS',
        video_url: videoUrl,
        caption,
        access_token: accessToken,
        share_to_feed: true,
      }),
    });

    const createData = (await createRes.json()) as any;

    if (!createData.id) {
      logger.error('Failed to create media container', { response: createData });
      return { id: '', success: false, error: createData.error?.message || 'Container creation failed' };
    }

    const containerId = createData.id;
    logger.info('Media container created', { containerId });

    // Step 2: Poll for container status
    let status = 'IN_PROGRESS';
    let attempts = 0;
    const maxAttempts = 30; // 5 minutes max (10s intervals)

    while (status === 'IN_PROGRESS' && attempts < maxAttempts) {
      await sleep(10000);
      attempts++;

      const statusRes = await fetch(
        `${GRAPH_API}/${containerId}?fields=status_code,status&access_token=${accessToken}`
      );
      const statusData = (await statusRes.json()) as any;
      status = statusData.status_code || 'ERROR';

      logger.debug('Container status poll', { containerId, status, attempt: attempts });
    }

    if (status !== 'FINISHED') {
      return { id: containerId, success: false, error: `Container status: ${status}` };
    }

    // Step 3: Publish
    const publishRes = await fetch(`${GRAPH_API}/${userId}/media_publish`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        creation_id: containerId,
        access_token: accessToken,
      }),
    });

    const publishData = (await publishRes.json()) as any;

    if (!publishData.id) {
      logger.error('Failed to publish reel', { response: publishData });
      return { id: containerId, success: false, error: publishData.error?.message || 'Publish failed' };
    }

    logger.info('Reel published', { mediaId: publishData.id });
    return { id: publishData.id, success: true };
  } catch (err: any) {
    logger.error('Instagram publish error', { error: err.message });
    return { id: '', success: false, error: err.message };
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms));
}
