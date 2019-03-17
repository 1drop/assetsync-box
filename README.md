# Neos asset sync from box.com

Based on `dl/assetsync`

```yaml
DL:
  AssetSync:
    sourceConfiguration:
      boxCom:
        sourceClass: Onedrop\AssetSync\Box\Source\BoxComSource
        fileIdentifierPattern: '.+\.(gif|jpg|jpeg|tiff|png|pdf|svg)'
        removeAssetsNotInSource: false
        assetTags:
          - boxCom
        sourceOptions:
          folderId: <folderId>
          clientId: <clientId>
          clientSecret: <clientSecret>
```
