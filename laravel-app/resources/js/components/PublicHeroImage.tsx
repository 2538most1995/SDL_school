import { useEffect, useMemo, useState } from 'react';
import {
    DEFAULT_HERO_IMAGE,
    publicAssetUrl,
    publicBrandingHeroPath,
    type PublicBranding,
} from '../lib/publicBranding';

type PublicHeroImageProps = {
    branding?: PublicBranding;
    alt: string;
    className: string;
};

export function PublicHeroImage({ branding, alt, className }: PublicHeroImageProps) {
    const primarySource = publicAssetUrl(branding?.heroImageUrl) ?? DEFAULT_HERO_IMAGE;
    const fallbackSource = useMemo(
        () => branding ? publicAssetUrl(publicBrandingHeroPath(branding.districtId)) : null,
        [branding],
    );
    const [failedSources, setFailedSources] = useState<string[]>([]);

    useEffect(() => {
        setFailedSources((sources) => sources.filter((source) => source === primarySource || source === fallbackSource));
    }, [fallbackSource, primarySource]);

    const source = [primarySource, fallbackSource]
        .filter((candidate): candidate is string => candidate !== null)
        .find((candidate) => !failedSources.includes(candidate));

    if (!source) return null;

    return (
        <img
            key={source}
            src={source}
            alt={alt}
            className={className}
            onError={() => setFailedSources((sources) => sources.includes(source) ? sources : [...sources, source])}
        />
    );
}
