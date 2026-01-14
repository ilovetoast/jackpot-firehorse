import FileTypeIcon from './FileTypeIcon'

export default function AssetPlaceholder({ asset, primaryColor, size = 'lg' }) {
  return (
    <div
      className="relative flex items-center justify-center w-full h-full rounded-lg overflow-hidden"
      style={{
        // backgroundColor: primaryColor,
      }}
    >
      <div className="flex items-center justify-center w-16 h-16 rounded-full bg-white/70 backdrop-blur-sm shadow-sm">
        <FileTypeIcon
          fileExtension={asset?.file_extension}
          mimeType={asset?.mime_type}
          size={size}
          className="text-gray-500"
        />
      </div>
    </div>
  )
}
